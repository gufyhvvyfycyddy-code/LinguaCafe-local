<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\Setting;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class PortableDataImportPlanService
{
    /** @return array<int,array<string,mixed>> */
    public function classify(
        array $items,
        int $userId,
        string $language,
        bool $includeScheduling = false,
        bool $lockForUpdate = false,
    ): array
    {
        $classified = [];
        $seen = [];
        $state = $this->classificationState($items, $userId, $language, $lockForUpdate);

        foreach ($items as $item) {
            $external = (string) ($item['external_id'] ?? '');
            if (isset($seen[$external])) {
                $classified[] = ['action' => 'conflict', 'reason' => 'duplicate_external_id', 'item' => $item, 'target_id' => null, 'target_hash' => null];
                continue;
            }
            $seen[$external] = true;
            preg_match('/^lc-sense:([a-f0-9]{16}):(\d{1,20})$/', $external, $match);
            $sourceId = (int) ($match[2] ?? 0);
            $sameOrigin = ($match[1] ?? '') === $this->portableOrigin($userId);
            $sourceOwner = $sameOrigin && $sourceId > 0
                ? ($state['source_owners'][$sourceId] ?? null)
                : null;
            if ($sourceOwner
                && ((int) $sourceOwner->user_id !== $userId
                    || (string) $sourceOwner->language_id !== $language)) {
                $classified[] = ['action' => 'conflict', 'reason' => 'external_id_owned_elsewhere', 'item' => $item, 'target_id' => null, 'target_hash' => null];
                continue;
            }
            $portableKey = $this->portableSenseKey($userId, $language, $external);
            $comparableKey = $this->contentComparableKey($item);
            $target = ($sameOrigin ? ($state['by_id'][$sourceId] ?? null) : null)
                ?: ($state['by_portable_key'][$portableKey] ?? null)
                ?: ($state['by_content'][$comparableKey] ?? null);
            if (! $target) {
                $classified[] = ['action' => 'create', 'reason' => null, 'item' => $item, 'target_id' => null, 'target_hash' => null];
                continue;
            }
            $current = $this->senseComparable(
                $target,
                $includeScheduling,
                $state['tags'][$target->id] ?? [],
                $state['cards'][$target->id] ?? null,
            );
            $incoming = $this->importComparable($item, $includeScheduling);
            $classified[] = [
                'action' => $current === $incoming ? 'skip' : 'update',
                'reason' => $current === $incoming ? 'identical' : 'content_changed',
                'item' => $item,
                'target_id' => $target->id,
                'target_hash' => hash('sha256', $this->encode($current)),
            ];
        }

        return $classified;
    }

    public function databaseFingerprint(
        array $classified,
        array $articles,
        array $settings,
        int $userId,
        string $language,
        bool $lockForUpdate = false,
    ): string
    {
        $bookNames = array_values(array_unique(array_filter(array_map(
            fn (array $book) => mb_substr(trim((string) ($book['name'] ?? '')), 0, 255),
            $articles,
        ))));
        $bookQuery = Book::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereIn('name', $bookNames)
            ->orderBy('id');
        if ($lockForUpdate) {
            $bookQuery->lockForUpdate();
        }
        $books = $bookQuery->get(['id', 'name']);
        $chapterQuery = Chapter::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereIn('book_id', $books->pluck('id'))
            ->orderBy('id');
        if ($lockForUpdate) {
            $chapterQuery->lockForUpdate();
        }
        $chapters = $chapterQuery->get([
            'id', 'book_id', 'name', 'raw_text', 'word_count', 'read_count',
        ]);
        $settingNames = array_values(array_unique(array_map(
            fn (array $setting) => (string) ($setting['name'] ?? ''),
            $settings,
        )));
        $settingQuery = Setting::query()
            ->where('user_id', $userId)
            ->whereIn('name', $settingNames)
            ->orderBy('id');
        if ($lockForUpdate) {
            $settingQuery->lockForUpdate();
        }
        $currentSettings = $settingQuery->get(['id', 'name', 'value']);

        return hash('sha256', $this->encode([
            'user_id' => $userId, 'language' => $language,
            'targets' => array_map(fn (array $entry) => [
                'external_id' => $entry['item']['external_id'],
                'target_id' => $entry['target_id'],
                'target_hash' => $entry['target_hash'],
                'action' => $entry['action'],
            ], $classified),
            'books' => $books->map->only(['id', 'name'])->all(),
            'chapters' => $chapters->map->only([
                'id', 'book_id', 'name', 'raw_text', 'word_count', 'read_count',
            ])->all(),
            'settings' => $currentSettings->map->only(['id', 'name', 'value'])->all(),
        ]));
    }

    public function validatedSchedule(array $item): array
    {
        $state = (string) ($item['fsrs_state'] ?? '');
        if (! in_array($state, ['new', 'learning', 'review', 'relearning'], true)) {
            throw new InvalidArgumentException('数据包包含无效 FSRS state。');
        }
        try {
            $due = Carbon::parse((string) $item['fsrs_due_at']);
            $lastReviewed = trim((string) ($item['fsrs_last_reviewed_at'] ?? '')) === ''
                ? null
                : Carbon::parse((string) $item['fsrs_last_reviewed_at']);
        } catch (Throwable) {
            throw new InvalidArgumentException('数据包包含无效 FSRS 日期。');
        }
        $stability = filter_var($item['fsrs_stability'], FILTER_VALIDATE_FLOAT);
        $difficulty = filter_var($item['fsrs_difficulty'], FILTER_VALIDATE_FLOAT);
        $reps = filter_var($item['fsrs_reps'], FILTER_VALIDATE_INT);
        $lapses = filter_var($item['fsrs_lapses'], FILTER_VALIDATE_INT);
        if ($stability === false || $stability < 0 || $stability > 36500
            || $difficulty === false || $difficulty < 1 || $difficulty > 10
            || $reps === false || $reps < 0 || $reps > 1000000
            || $lapses === false || $lapses < 0 || $lapses > $reps) {
            throw new InvalidArgumentException('数据包包含超出范围的 FSRS 数值。');
        }

        return [
            'fsrs_state' => $state,
            'fsrs_due_at' => $due,
            'fsrs_stability' => (float) $stability,
            'fsrs_difficulty' => (float) $difficulty,
            'fsrs_reps' => (int) $reps,
            'fsrs_lapses' => (int) $lapses,
            'fsrs_last_reviewed_at' => $lastReviewed,
            'fsrs_enabled' => true,
        ];
    }

    public function portableOrigin(int $userId): string
    {
        return substr($this->portableUserFingerprint($userId), 0, 16);
    }

    public function portableUserFingerprint(int $userId): string
    {
        return hash_hmac(
            'sha256',
            'portable-data-user:' . $userId,
            (string) config('app.key'),
        );
    }

    public function portableSenseKey(int $userId, string $language, string $externalId): string
    {
        return hash('sha256', implode('|', ['portable-v1', $userId, $language, $externalId]));
    }

    private function classificationState(
        array $items,
        int $userId,
        string $language,
        bool $lockForUpdate,
    ): array
    {
        if ($items === []) {
            return [
                'source_owners' => [],
                'by_id' => [],
                'by_portable_key' => [],
                'by_content' => [],
                'tags' => [],
                'cards' => [],
            ];
        }

        $origin = $this->portableOrigin($userId);
        $sourceIds = [];
        $portableKeys = [];
        $lemmas = [];
        foreach ($items as $item) {
            $external = (string) $item['external_id'];
            if (preg_match('/^lc-sense:([a-f0-9]{16}):(\d{1,20})$/', $external, $match)
                && $match[1] === $origin) {
                $sourceIds[] = (int) $match[2];
            }
            $portableKeys[] = $this->portableSenseKey($userId, $language, $external);
            $lemmas[] = (string) $item['lemma'];
        }
        $sourceIds = array_values(array_unique($sourceIds));
        $portableKeys = array_values(array_unique($portableKeys));
        $lemmas = array_values(array_unique($lemmas));

        $sourceOwners = $sourceIds === []
            ? collect()
            : WordSense::query()->whereIn('id', $sourceIds)->get()->keyBy('id');
        $candidateQuery = WordSense::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where(function ($query) use ($sourceIds, $portableKeys, $lemmas) {
                if ($sourceIds !== []) {
                    $query->whereIn('id', $sourceIds);
                }
                $method = $sourceIds === [] ? 'whereIn' : 'orWhereIn';
                $query->{$method}('sense_key', $portableKeys);
                $query->orWhereIn('lemma', $lemmas);
            })
            ->orderBy('id');
        if ($lockForUpdate) {
            $candidateQuery->lockForUpdate();
        }
        $candidates = $candidateQuery->get();
        $candidateIds = $candidates->pluck('id');

        $cardQuery = ReviewCard::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->whereIn('target_id', $candidateIds)
            ->orderBy('id');
        if ($lockForUpdate) {
            $cardQuery->lockForUpdate();
        }
        $cards = $cardQuery->get()->keyBy('target_id');

        $tagQuery = DB::table('word_sense_tag_assignments as assignments')
            ->join('word_sense_tags as tags', 'tags.id', '=', 'assignments.word_sense_tag_id')
            ->where('tags.user_id', $userId)
            ->where('tags.language_id', $language)
            ->whereIn('assignments.word_sense_id', $candidateIds)
            ->orderBy('tags.normalized_name');
        if ($lockForUpdate) {
            $tagQuery->lockForUpdate();
        }
        $tags = [];
        foreach ($tagQuery->get(['assignments.word_sense_id', 'tags.name']) as $tag) {
            $tags[$tag->word_sense_id][] = $tag->name;
        }

        $byPortableKey = [];
        $byContent = [];
        foreach ($candidates as $candidate) {
            $byPortableKey[$candidate->sense_key] ??= $candidate;
            $byContent[$this->senseContentComparableKey($candidate)] ??= $candidate;
        }

        return [
            'source_owners' => $sourceOwners->all(),
            'by_id' => $candidates->keyBy('id')->all(),
            'by_portable_key' => $byPortableKey,
            'by_content' => $byContent,
            'tags' => $tags,
            'cards' => $cards->all(),
        ];
    }

    private function senseComparable(
        WordSense $sense,
        bool $includeScheduling,
        array $tags,
        ?ReviewCard $card,
    ): array
    {
        $comparable = [
            'surface_form' => (string) $sense->surface_form, 'lemma' => (string) $sense->lemma,
            'pos' => (string) $sense->pos, 'sense_zh' => (string) $sense->sense_zh,
            'sense_en' => (string) $sense->sense_en,
            'example_sentence_en' => (string) $sense->example_sentence_en,
            'example_sentence_zh' => (string) $sense->example_sentence_zh,
            'tags' => $tags,
        ];
        if ($includeScheduling) {
            $comparable['schedule'] = $card ? [
                'fsrs_state' => (string) $card->fsrs_state,
                'fsrs_due_at' => optional($card->fsrs_due_at)->toISOString(),
                'fsrs_stability' => (float) $card->fsrs_stability,
                'fsrs_difficulty' => (float) $card->fsrs_difficulty,
                'fsrs_reps' => (int) $card->fsrs_reps,
                'fsrs_lapses' => (int) $card->fsrs_lapses,
                'fsrs_last_reviewed_at' => optional($card->fsrs_last_reviewed_at)->toISOString(),
            ] : null;
        }

        return $comparable;
    }

    private function contentComparableKey(array $item): string
    {
        return $this->encode([
            (string) $item['lemma'],
            (string) $item['pos'],
            (string) $item['sense_zh'],
        ]);
    }

    private function senseContentComparableKey(WordSense $sense): string
    {
        return $this->encode([
            (string) $sense->lemma,
            (string) $sense->pos,
            (string) $sense->sense_zh,
        ]);
    }

    private function importComparable(array $item, bool $includeScheduling): array
    {
        $tags = $item['tags'];
        sort($tags, SORT_NATURAL | SORT_FLAG_CASE);
        $comparable = [
            'surface_form' => $item['surface_form'], 'lemma' => $item['lemma'], 'pos' => $item['pos'],
            'sense_zh' => $item['sense_zh'], 'sense_en' => $item['sense_en'],
            'example_sentence_en' => $item['example_sentence_en'],
            'example_sentence_zh' => $item['example_sentence_zh'], 'tags' => $tags,
        ];
        if ($includeScheduling) {
            $schedule = $this->validatedSchedule($item);
            $comparable['schedule'] = [
                'fsrs_state' => $schedule['fsrs_state'],
                'fsrs_due_at' => $schedule['fsrs_due_at']->toISOString(),
                'fsrs_stability' => (float) $schedule['fsrs_stability'],
                'fsrs_difficulty' => (float) $schedule['fsrs_difficulty'],
                'fsrs_reps' => (int) $schedule['fsrs_reps'],
                'fsrs_lapses' => (int) $schedule['fsrs_lapses'],
                'fsrs_last_reviewed_at' => $schedule['fsrs_last_reviewed_at']?->toISOString(),
            ];
        }

        return $comparable;
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
