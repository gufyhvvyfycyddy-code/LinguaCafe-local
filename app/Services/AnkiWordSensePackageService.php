<?php

namespace App\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

class AnkiWordSensePackageService
{
    public const NOTE_TYPE = 'LinguaCafe WordSense v1';
    public const DECK_NAME = 'LinguaCafe::WordSense';
    public const MODEL_ID = 1767225600001;
    public const DECK_ID = 1767225600002;
    public const MAX_PACKAGE_BYTES = 26214400;
    public const MAX_ENTRY_BYTES = 20971520;
    public const MAX_ENTRIES = 32;
    private const QUESTION_TEMPLATE = '<div class="example">{{ExampleEn}}</div><div class="prompt">{{Surface}} · {{Lemma}} · {{POS}}</div>';
    private const ANSWER_TEMPLATE = '{{FrontSide}}<hr id="answer"><div class="sense">{{SenseZh}}</div><div>{{SenseEn}}</div><div class="translation">{{ExampleZh}}</div><div class="source">{{Source}}</div>';

    public const FIELDS = [
        'LinguaCafeId', 'Surface', 'Lemma', 'POS', 'SenseZh', 'SenseEn',
        'ExampleEn', 'ExampleZh', 'Source', 'Tags', 'FsrsState', 'FsrsDueAt',
        'FsrsStability', 'FsrsDifficulty', 'FsrsReps', 'FsrsLapses',
        'FsrsLastReviewedAt',
    ];

    /**
     * @return array{path:string,count:int,sha256:string}
     */
    public function build(
        Collection $items,
        bool $includeScheduling = false,
        string $namespace = 'linguacafe-default',
    ): array
    {
        if ($items->count() > ReviewCardExportService::EXPORT_LIMIT) {
            throw new InvalidArgumentException('The Anki export exceeds the supported record limit.');
        }

        $directory = storage_path('app/temp/anki-' . bin2hex(random_bytes(12)));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the Anki export workspace.');
        }

        $databasePath = $directory . DIRECTORY_SEPARATOR . 'collection.anki2';
        $packagePath = $directory . DIRECTORY_SEPARATOR . 'linguacafe-wordsenses.apkg';

        try {
            $this->createCollection($databasePath, $items, $includeScheduling, $namespace);
            $zip = new ZipArchive();
            if ($zip->open($packagePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the Anki package.');
            }
            try {
                if (! $zip->addFile($databasePath, 'collection.anki2')
                    || ! $zip->addFromString('media', '{}')) {
                    throw new RuntimeException('Unable to write the Anki package.');
                }
            } finally {
                $zip->close();
            }

            return [
                'path' => $packagePath,
                'count' => $items->count(),
                'sha256' => hash_file('sha256', $packagePath),
            ];
        } catch (Throwable $exception) {
            $this->deleteDirectory($directory);
            throw $exception;
        } finally {
            if (is_file($databasePath)) {
                @unlink($databasePath);
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $packagePath): array
    {
        if (! is_file($packagePath) || filesize($packagePath) > self::MAX_PACKAGE_BYTES) {
            throw new InvalidArgumentException('The Anki package is missing or exceeds 25 MiB.');
        }

        $zip = new ZipArchive();
        if ($zip->open($packagePath) !== true) {
            throw new InvalidArgumentException('The uploaded file is not a readable Anki package.');
        }

        $databaseBytes = null;
        $seen = [];
        $totalSize = 0;
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new InvalidArgumentException('The Anki package contains too many entries.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = (string) ($stat['name'] ?? '');
                $normalized = str_replace('\\', '/', $name);
                if ($name === '' || str_starts_with($normalized, '/')
                    || preg_match('/(^|\/)\.\.(\/|$)/', $normalized)
                    || preg_match('/^[A-Za-z]:\//', $normalized)) {
                    throw new InvalidArgumentException('The Anki package contains an unsafe entry path.');
                }
                if (isset($seen[$name])) {
                    throw new InvalidArgumentException('The Anki package contains duplicate entries.');
                }
                $seen[$name] = true;
                if ((int) ($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
                    throw new InvalidArgumentException('The Anki package contains an oversized entry.');
                }
                $totalSize += (int) ($stat['size'] ?? 0);
                if ($totalSize > 52428800) {
                    throw new InvalidArgumentException('The Anki package expands beyond the supported limit.');
                }
                if (! in_array($name, ['collection.anki2', 'media'], true)) {
                    throw new InvalidArgumentException('Only the fixed LinguaCafe Anki package format is supported.');
                }
                if ($name === 'collection.anki2') {
                    $databaseBytes = $zip->getFromIndex($index);
                } elseif ($zip->getFromIndex($index) !== '{}') {
                    throw new InvalidArgumentException('The fixed LinguaCafe Anki package does not contain media.');
                }
            }
        } finally {
            $zip->close();
        }

        if (! is_string($databaseBytes) || $databaseBytes === '') {
            throw new InvalidArgumentException('The Anki package has no collection.anki2 database.');
        }

        $databasePath = storage_path('app/temp/anki-import-' . bin2hex(random_bytes(12)) . '.anki2');
        if (file_put_contents($databasePath, $databaseBytes, LOCK_EX) === false) {
            throw new RuntimeException('Unable to inspect the Anki package.');
        }

        try {
            return $this->readCollection($databasePath);
        } finally {
            @unlink($databasePath);
        }
    }

    public function cleanupPackage(string $packagePath): void
    {
        $directory = dirname($packagePath);
        if (str_starts_with(str_replace('\\', '/', $directory), str_replace('\\', '/', storage_path('app/temp/anki-')))) {
            $this->deleteDirectory($directory);
        }
    }

    private function createCollection(
        string $path,
        Collection $items,
        bool $includeScheduling,
        string $namespace,
    ): void
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA journal_mode=OFF');
        $pdo->exec('CREATE TABLE col (id integer primary key, crt integer not null, mod integer not null, scm integer not null, ver integer not null, dty integer not null, usn integer not null, ls integer not null, conf text not null, models text not null, decks text not null, dconf text not null, tags text not null)');
        $pdo->exec('CREATE TABLE notes (id integer primary key, guid text not null, mid integer not null, mod integer not null, usn integer not null, tags text not null, flds text not null, sfld integer not null, csum integer not null, flags integer not null, data text not null)');
        $pdo->exec('CREATE TABLE cards (id integer primary key, nid integer not null, did integer not null, ord integer not null, mod integer not null, usn integer not null, type integer not null, queue integer not null, due integer not null, ivl integer not null, factor integer not null, reps integer not null, lapses integer not null, left integer not null, odue integer not null, odid integer not null, flags integer not null, data text not null)');
        $pdo->exec('CREATE TABLE revlog (id integer primary key, cid integer not null, usn integer not null, ease integer not null, ivl integer not null, lastIvl integer not null, factor integer not null, time integer not null, type integer not null)');
        $pdo->exec('CREATE TABLE graves (usn integer not null, oid integer not null, type integer not null)');
        $pdo->exec('CREATE INDEX ix_notes_usn ON notes (usn)');
        $pdo->exec('CREATE INDEX ix_cards_usn ON cards (usn)');
        $pdo->exec('CREATE INDEX ix_cards_nid ON cards (nid)');

        $now = time();
        $model = $this->modelDefinition($now);
        $deck = $this->deckDefinition($now);
        $col = $pdo->prepare('INSERT INTO col VALUES (1, :crt, :mod, :scm, 11, 0, 0, 0, :conf, :models, :decks, :dconf, :tags)');
        $col->execute([
            'crt' => strtotime(gmdate('Y-m-d 00:00:00')),
            'mod' => $now * 1000,
            'scm' => $now * 1000,
            'conf' => json_encode(['activeDecks' => [self::DECK_ID], 'curDeck' => self::DECK_ID, 'newSpread' => 0, 'collapseTime' => 1200]),
            'models' => json_encode([(string) self::MODEL_ID => $model], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'decks' => json_encode([(string) self::DECK_ID => $deck], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'dconf' => json_encode(['1' => ['id' => 1, 'name' => 'Default', 'mod' => $now, 'usn' => 0, 'maxTaken' => 60, 'autoplay' => true, 'timer' => 0, 'replayq' => true, 'new' => ['bury' => false, 'delays' => [1, 10], 'initialFactor' => 2500, 'ints' => [1, 4], 'order' => 1, 'perDay' => 20], 'rev' => ['bury' => false, 'ease4' => 1.3, 'fuzz' => 0.05, 'ivlFct' => 1, 'maxIvl' => 36500, 'perDay' => 200, 'hardFactor' => 1.2], 'lapse' => ['delays' => [10], 'leechAction' => 0, 'leechFails' => 8, 'minInt' => 1, 'mult' => 0]]]),
            'tags' => '{}',
        ]);

        $note = $pdo->prepare('INSERT INTO notes VALUES (:id, :guid, :mid, :mod, 0, :tags, :flds, :sfld, :csum, 0, "")');
        $card = $pdo->prepare('INSERT INTO cards VALUES (:id, :nid, :did, 0, :mod, 0, :type, :queue, :due, :ivl, 2500, :reps, :lapses, 0, 0, 0, 0, "")');

        foreach ($items->values() as $index => $item) {
            $senseId = (int) ($item['word_sense_id'] ?? 0);
            if ($senseId < 1) {
                throw new InvalidArgumentException('Every exported card must reference a WordSense.');
            }
            $noteId = 1767225601000 + $index;
            $cardId = 1767226601000 + $index;
            $fields = $this->itemFields($item, $namespace);
            $lcId = $fields[0];
            $note->execute([
                'id' => $noteId,
                'guid' => 'lcws' . substr(hash('sha256', $namespace . ':' . $senseId), 0, 20),
                'mid' => self::MODEL_ID,
                'mod' => $now,
                'tags' => $this->ankiTags((array) ($item['tags'] ?? [])),
                'flds' => implode("\x1f", $fields),
                'sfld' => $lcId,
                'csum' => (int) hexdec(substr(sha1($lcId), 0, 8)),
            ]);
            $schedule = $this->ankiSchedule($item, $includeScheduling, $index);
            $card->execute([
                'id' => $cardId,
                'nid' => $noteId,
                'did' => self::DECK_ID,
                'mod' => $now,
                'type' => $schedule['type'],
                'queue' => $schedule['queue'],
                'due' => $schedule['due'],
                'ivl' => $schedule['ivl'],
                'reps' => $schedule['reps'],
                'lapses' => $schedule['lapses'],
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function readCollection(string $path): array
    {
        try {
            $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA query_only=ON');
            if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                throw new InvalidArgumentException('The Anki collection database failed integrity checking.');
            }
            $collection = $pdo->query('SELECT models, decks FROM col LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $models = json_decode((string) ($collection['models'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $decks = json_decode((string) ($collection['decks'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $model = $models[(string) self::MODEL_ID] ?? null;
            $template = $model['tmpls'][0] ?? null;
            $deck = $decks[(string) self::DECK_ID] ?? null;
            if (count($models) !== 1
                || count($decks) !== 1
                || ! is_array($model)
                || ($model['name'] ?? null) !== self::NOTE_TYPE
                || array_map(fn ($field) => $field['name'] ?? null, $model['flds'] ?? []) !== self::FIELDS
                || count($model['tmpls'] ?? []) !== 1
                || ! is_array($template)
                || ($template['name'] ?? null) !== 'Sense Card'
                || ($template['ord'] ?? null) !== 0
                || ($template['qfmt'] ?? null) !== self::QUESTION_TEMPLATE
                || ($template['afmt'] ?? null) !== self::ANSWER_TEMPLATE
                || ! is_array($deck)
                || ($deck['name'] ?? null) !== self::DECK_NAME) {
                throw new InvalidArgumentException('The Anki package does not use the fixed LinguaCafe WordSense template.');
            }

            $rows = $pdo->query('SELECT n.flds, n.tags, c.ord, c.did FROM notes n JOIN cards c ON c.nid = n.id WHERE n.mid = ' . self::MODEL_ID)->fetchAll(PDO::FETCH_ASSOC);
            if ((int) $pdo->query('SELECT COUNT(*) FROM notes')->fetchColumn() !== count($rows)
                || (int) $pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn() !== count($rows)
                || (int) $pdo->query('SELECT COUNT(*) FROM revlog')->fetchColumn() !== 0
                || (int) $pdo->query('SELECT COUNT(*) FROM graves')->fetchColumn() !== 0) {
                throw new InvalidArgumentException('The Anki package contains data outside the fixed LinguaCafe template.');
            }
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The Anki collection database is invalid.', previous: $exception);
        }

        if (count($rows) > ReviewCardExportService::EXPORT_LIMIT) {
            throw new InvalidArgumentException('The Anki package exceeds the supported record limit.');
        }

        $items = [];
        foreach ($rows as $row) {
            if ((int) $row['ord'] !== 0 || (int) $row['did'] !== self::DECK_ID) {
                throw new InvalidArgumentException('The Anki package contains an unsupported card template.');
            }
            $fields = explode("\x1f", (string) $row['flds']);
            if (count($fields) !== count(self::FIELDS)
                || ! preg_match('/^lc-sense:([a-f0-9]{16}):(\d{1,20})$/', $fields[0], $match)) {
                throw new InvalidArgumentException('The Anki package contains an invalid LinguaCafe identifier or field mapping.');
            }
            $items[] = $this->fieldsToItem($fields, (string) $row['tags']);
        }

        return $items;
    }

    /** @return array<int,string> */
    private function itemFields(array $item, string $namespace): array
    {
        $tags = $this->tagNames((array) ($item['tags'] ?? []));
        return array_map(
            fn ($value) => str_replace("\x1f", ' ', (string) ($value ?? '')),
            [
                'lc-sense:' . substr(hash('sha256', $namespace), 0, 16) . ':' . (int) $item['word_sense_id'],
                $item['surface_form'] ?? '',
                $item['lemma'] ?? '',
                $item['pos'] ?? '',
                $item['sense_zh'] ?? '',
                $item['sense_en'] ?? '',
                $item['example_sentence_en'] ?? '',
                $item['example_sentence_zh'] ?? '',
                $item['source_chapter_title'] ?? '',
                implode(', ', $tags),
                $item['fsrs_state'] ?? '',
                $item['fsrs_due_at'] ?? '',
                $item['fsrs_stability'] ?? '',
                $item['fsrs_difficulty'] ?? '',
                $item['fsrs_reps'] ?? 0,
                $item['fsrs_lapses'] ?? 0,
                $item['fsrs_last_reviewed_at'] ?? '',
            ],
        );
    }

    private function fieldsToItem(array $fields, string $ankiTags): array
    {
        preg_match('/^lc-sense:([a-f0-9]{16}):(\d{1,20})$/', $fields[0], $match);
        return [
            'external_id' => $fields[0],
            'source_word_sense_id' => (int) $match[2],
            'surface_form' => $fields[1],
            'lemma' => $fields[2],
            'pos' => $fields[3],
            'sense_zh' => $fields[4],
            'sense_en' => $fields[5],
            'example_sentence_en' => $fields[6],
            'example_sentence_zh' => $fields[7],
            'source' => $fields[8],
            'tags' => array_values(array_unique(array_filter(array_merge(
                preg_split('/\s*,\s*/u', $fields[9], -1, PREG_SPLIT_NO_EMPTY) ?: [],
                preg_split('/\s+/u', trim($ankiTags), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            )))),
            'fsrs_state' => $fields[10],
            'fsrs_due_at' => $fields[11],
            'fsrs_stability' => $fields[12],
            'fsrs_difficulty' => $fields[13],
            'fsrs_reps' => $fields[14],
            'fsrs_lapses' => $fields[15],
            'fsrs_last_reviewed_at' => $fields[16],
        ];
    }

    private function modelDefinition(int $now): array
    {
        $fields = [];
        foreach (self::FIELDS as $ordinal => $name) {
            $fields[] = ['name' => $name, 'ord' => $ordinal, 'sticky' => false, 'rtl' => false, 'font' => 'Arial', 'size' => 20, 'media' => []];
        }

        return [
            'id' => self::MODEL_ID, 'name' => self::NOTE_TYPE, 'type' => 0,
            'mod' => $now, 'usn' => 0, 'sortf' => 0, 'did' => self::DECK_ID,
            'tmpls' => [[
                'name' => 'Sense Card', 'ord' => 0,
                'qfmt' => self::QUESTION_TEMPLATE,
                'afmt' => self::ANSWER_TEMPLATE,
                'bqfmt' => '', 'bafmt' => '', 'did' => null,
            ]],
            'flds' => $fields,
            'css' => '.card{font-family:Arial;font-size:22px;text-align:left;color:#202124;background:#fff;line-height:1.55}.example{font-size:27px;margin-bottom:18px}.prompt,.source{color:#65758b}.sense{font-size:25px;font-weight:700;margin:14px 0}.translation{margin-top:12px}',
            'latexPre' => '', 'latexPost' => '', 'latexsvg' => false, 'req' => [[0, 'all', [0, 4]]],
            'vers' => [], 'tags' => [],
        ];
    }

    private function deckDefinition(int $now): array
    {
        return ['id' => self::DECK_ID, 'name' => self::DECK_NAME, 'mod' => $now, 'usn' => 0, 'desc' => 'Fixed LinguaCafe WordSense export.', 'dyn' => 0, 'collapsed' => false, 'conf' => 1, 'extendNew' => 0, 'extendRev' => 0];
    }

    private function ankiSchedule(array $item, bool $include, int $index): array
    {
        if (! $include || empty($item['fsrs_state']) || ($item['fsrs_state'] ?? 'new') === 'new') {
            return ['type' => 0, 'queue' => 0, 'due' => $index + 1, 'ivl' => 0, 'reps' => 0, 'lapses' => 0];
        }
        $state = (string) $item['fsrs_state'];
        if (in_array($state, ['learning', 'relearning'], true)) {
            return [
                'type' => $state === 'relearning' ? 3 : 1,
                'queue' => 1,
                'due' => max(time(), strtotime((string) ($item['fsrs_due_at'] ?? 'now')) ?: time()),
                'ivl' => max(1, (int) round((float) ($item['fsrs_stability'] ?? 1))),
                'reps' => max(0, (int) ($item['fsrs_reps'] ?? 0)),
                'lapses' => max(0, (int) ($item['fsrs_lapses'] ?? 0)),
            ];
        }
        return [
            'type' => 2, 'queue' => 2,
            'due' => max(1, (int) floor(((strtotime((string) ($item['fsrs_due_at'] ?? 'now')) ?: time()) - strtotime(gmdate('Y-m-d 00:00:00'))) / 86400)),
            'ivl' => max(1, (int) round((float) ($item['fsrs_stability'] ?? 1))),
            'reps' => max(0, (int) ($item['fsrs_reps'] ?? 0)),
            'lapses' => max(0, (int) ($item['fsrs_lapses'] ?? 0)),
        ];
    }

    private function ankiTags(array $tags): string
    {
        $names = array_map(fn ($name) => preg_replace('/\s+/u', '_', trim($name)) ?? '', $this->tagNames($tags));
        return ' ' . implode(' ', array_filter($names)) . ' ';
    }

    private function tagNames(array $tags): array
    {
        return array_values(array_filter(array_map(
            fn ($tag) => trim((string) (is_array($tag) ? ($tag['name'] ?? '') : $tag)),
            $tags,
        )));
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
