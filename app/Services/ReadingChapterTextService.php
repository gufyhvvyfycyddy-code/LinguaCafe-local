<?php

namespace App\Services;

use App\Models\Chapter;

class ReadingChapterTextService
{
    public function chapterForUser(int $userId, string $language, int $chapterId): Chapter
    {
        $chapter = Chapter::query()
            ->where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            throw new \InvalidArgumentException('Chapter does not exist in the current user and language scope.');
        }

        return $chapter;
    }

    public function resolveChapter(int $userId, string $language, int $chapterId): Chapter
    {
        return $this->chapterForUser($userId, $language, $chapterId);
    }

    public function sourceRevision(Chapter $chapter): string
    {
        $rawHash = hash('sha256', (string) ($chapter->raw_text ?? ''));
        $processedHash = hash('sha256', (string) ($chapter->processed_text ?? ''));

        return 'sha256:' . hash('sha256', $rawHash . ':' . $processedHash);
    }

    /**
     * @return array<int, object>
     */
    public function tokenMap(Chapter $chapter): array
    {
        try {
            $tokens = $chapter->getProcessedText();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Chapter processed text is unavailable.');
        }

        if (!is_array($tokens)) {
            throw new \InvalidArgumentException('Chapter processed text is invalid.');
        }

        $map = [];
        foreach ($tokens as $arrayIndex => $token) {
            if (!is_object($token)) {
                continue;
            }

            $wordIndex = isset($token->word_index) ? (int) $token->word_index : (int) $arrayIndex;
            if (isset($map[$wordIndex])) {
                throw new \InvalidArgumentException('Chapter processed text contains duplicate word indexes.');
            }

            $map[$wordIndex] = $token;
        }

        ksort($map, SORT_NUMERIC);

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public function sentenceMap(Chapter $chapter): array
    {
        $sentences = [];
        foreach ($this->tokenMap($chapter) as $token) {
            if ($this->isStructure($token) || !isset($token->sentence_index)) {
                continue;
            }

            $sentenceIndex = (int) $token->sentence_index;
            $word = (string) ($token->word ?? '');
            if ($word === '') {
                continue;
            }

            if (!isset($sentences[$sentenceIndex])) {
                $sentences[$sentenceIndex] = '';
            }

            $sentences[$sentenceIndex] .= $word;
            if (($token->spaceAfter ?? true) === true) {
                $sentences[$sentenceIndex] .= ' ';
            }
        }

        foreach ($sentences as $sentenceIndex => $text) {
            $sentences[$sentenceIndex] = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        }
        ksort($sentences, SORT_NUMERIC);

        return $sentences;
    }

    /**
     * @return array{
     *   occurrence_id:string,
     *   kind:string,
     *   start_word_index:int,
     *   end_word_index:int,
     *   sentence_index:int,
     *   surface:string,
     *   lemma:string,
     *   pos:?string,
     *   source_sentence:string
     * }
     */
    public function canonicalTarget(
        Chapter $chapter,
        int $userId,
        string $language,
        string $kind,
        int $startWordIndex,
        int $endWordIndex
    ): array {
        if (!in_array($kind, ['word', 'phrase'], true)) {
            throw new \InvalidArgumentException('Target kind must be word or phrase.');
        }
        if ($startWordIndex < 0 || $endWordIndex < $startWordIndex) {
            throw new \InvalidArgumentException('Target span is invalid.');
        }
        if ($kind === 'word' && $startWordIndex !== $endWordIndex) {
            throw new \InvalidArgumentException('Word targets must contain exactly one token.');
        }
        if ($kind === 'phrase' && $startWordIndex === $endWordIndex) {
            throw new \InvalidArgumentException('Phrase targets must contain more than one token.');
        }

        $tokens = $this->tokenMap($chapter);
        $span = [];
        for ($index = $startWordIndex; $index <= $endWordIndex; $index++) {
            if (!array_key_exists($index, $tokens)) {
                throw new \InvalidArgumentException('Target span is not contiguous in the current chapter.');
            }

            $token = $tokens[$index];
            if ($this->isStructure($token)) {
                throw new \InvalidArgumentException('Target span cannot include structure or newline tokens.');
            }
            if (!isset($token->sentence_index)) {
                throw new \InvalidArgumentException('Target token has no sentence identity.');
            }

            $span[] = $token;
        }

        $sentenceIndex = (int) $span[0]->sentence_index;
        foreach ($span as $token) {
            if ((int) $token->sentence_index !== $sentenceIndex) {
                throw new \InvalidArgumentException('Phrase targets cannot cross sentence boundaries.');
            }
        }

        $surface = '';
        foreach ($span as $token) {
            $surface .= (string) ($token->word ?? '');
            if (($token->spaceAfter ?? true) === true) {
                $surface .= ' ';
            }
        }
        $surface = trim($surface);
        if ($surface === '') {
            throw new \InvalidArgumentException('Target surface is empty.');
        }

        $first = $span[0];
        $lemma = trim(mb_strtolower((string) ($first->lemma ?? $first->word ?? '')));
        $pos = isset($first->pos) && trim((string) $first->pos) !== '' ? (string) $first->pos : null;

        if ($kind === 'phrase') {
            $lemmas = [];
            $poses = [];
            foreach ($span as $token) {
                $lemmas[] = trim(mb_strtolower((string) ($token->lemma ?? $token->word ?? '')));
                if (isset($token->pos) && trim((string) $token->pos) !== '') {
                    $poses[] = (string) $token->pos;
                }
            }
            $lemma = trim(implode(' ', array_filter($lemmas, fn ($value) => $value !== '')));
            $pos = empty($poses) ? null : implode('+', $poses);
        }

        $sourceRevision = $this->sourceRevision($chapter);
        $sentences = $this->sentenceMap($chapter);

        return [
            'occurrence_id' => $this->occurrenceId(
                $userId,
                $language,
                (int) $chapter->id,
                $sourceRevision,
                $kind,
                $startWordIndex,
                $endWordIndex,
            ),
            'kind' => $kind,
            'start_word_index' => $startWordIndex,
            'end_word_index' => $endWordIndex,
            'sentence_index' => $sentenceIndex,
            'surface' => $surface,
            'lemma' => $lemma,
            'pos' => $pos,
            'source_sentence' => $sentences[$sentenceIndex] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function canonicalSpan(
        Chapter $chapter,
        int $userId,
        string $language,
        string $kind,
        int $startWordIndex,
        int $endWordIndex
    ): array {
        $canonical = $this->canonicalTarget($chapter, $userId, $language, $kind, $startWordIndex, $endWordIndex);
        $canonical['user_id'] = $userId;
        $canonical['language_id'] = $language;
        $canonical['chapter_id'] = (int) $chapter->id;
        $canonical['source_revision'] = $this->sourceRevision($chapter);
        $canonical['sentence_text'] = $canonical['source_sentence'];

        return $canonical;
    }

    public function occurrenceId(
        int $userId,
        string $language,
        int $chapterId,
        string $sourceRevision,
        string $kind,
        int $startWordIndex,
        int $endWordIndex
    ): string {
        $identity = json_encode([
            'user_id' => $userId,
            'language' => $language,
            'chapter_id' => $chapterId,
            'source_revision' => $sourceRevision,
            'kind' => $kind,
            'start_word_index' => $startWordIndex,
            'end_word_index' => $endWordIndex,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return 'occ2_' . hash('sha256', $identity);
    }

    private function isStructure(object $token): bool
    {
        return (($token->is_structure ?? false) === true)
            || (($token->pos ?? null) === 'STRUCT')
            || in_array((string) ($token->word ?? ''), ['NEWLINE', 'PARAGRAPH_BREAK'], true);
    }
}
