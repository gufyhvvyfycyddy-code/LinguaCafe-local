<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ChapterAiReadingAssist;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AiReadingAssistService
{
    /**
     * Build the full AI analysis prompt for a given chapter.
     *
     * @return array{success: bool, chapter_id?: int, chapter_title?: string, prompt?: string, article_word_count?: int, message?: string}
     */
    public function buildPromptForChapter(int $userId, string $language, int $chapterId): array
    {
        $chapter = Chapter::where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            return [
                'success' => false,
                'message' => '章节不存在或不属于当前用户。',
            ];
        }

        $rawText = $chapter->raw_text ?? '';
        $cleaned = $this->cleanRawText($rawText);
        $wordCount = str_word_count($cleaned);

        // Build sentence list from processed_text for alignment
        $sentenceList = $this->buildSentenceList($chapter);
        $prompt = $this->buildPromptText($cleaned, $sentenceList);

        return [
            'success' => true,
            'chapter_id' => (int) $chapter->id,
            'chapter_title' => $chapter->name,
            'prompt' => $prompt,
            'article_word_count' => $wordCount,
            'sentence_count' => count($sentenceList),
        ];
    }

    /**
     * Build a sentence list from the chapter's processed_text, using the same
     * sentence_index values that the reader frontend uses for token rendering.
     *
     * Returns an array of ['sentence_index' => int, 'text' => string].
     * Returns empty array if the chapter is not yet processed.
     */
    public function buildSentenceList(Chapter $chapter): array
    {
        try {
            $words = $chapter->getProcessedText();
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($words) || empty($words)) {
            return [];
        }

        $sentences = []; // sentence_index => word list
        $indexesInOrder = [];

        foreach ($words as $w) {
            if (!is_object($w) || !empty($w->is_structure) || !isset($w->sentence_index)) {
                continue;
            }
            $si = (int) $w->sentence_index;
            if (!isset($sentences[$si])) {
                $sentences[$si] = [];
                $indexesInOrder[] = $si;
            }
            $text = $w->word ?? '';
            $sentences[$si][] = $text;
        }

        $list = [];
        foreach ($indexesInOrder as $si) {
            $text = implode(' ', array_filter($sentences[$si], function ($t) { return $t !== ''; }));
            // Collapse multiple spaces
            $text = preg_replace('/\s+/', ' ', trim($text));
            $list[] = [
                'sentence_index' => $si,
                'text' => $text,
            ];
        }

        return $list;
    }

    /**
     * Preview-parse AI returned text without writing any data.
     *
     * @return array
     */
    public function previewImport(int $userId, string $language, int $chapterId, string $rawAiText): array
    {
        // 1. Verify chapter ownership
        $chapter = Chapter::where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            return [
                'success' => false,
                'parsed' => false,
                'message' => '章节不存在或不属于当前用户。',
            ];
        }

        // 2. Extract JSON payload
        $extracted = $this->extractJsonPayload($rawAiText);
        if (!$extracted['success']) {
            return [
                'success' => false,
                'parsed' => false,
                'message' => $extracted['message'],
                'errors' => $extracted['errors'] ?? [],
            ];
        }

        $payload = $extracted['payload'];

        // 3. Validate payload
        $validation = $this->validatePayload($payload);
        if (!$validation['success']) {
            return [
                'success' => false,
                'parsed' => false,
                'message' => $validation['message'],
                'errors' => $validation['errors'] ?? [],
            ];
        }

        // 4. Validate sentence alignment
        $alignment = $this->validateSentenceAlignment($payload, $chapter);
        if (!$alignment['success']) {
            return [
                'success' => false,
                'parsed' => false,
                'message' => $alignment['message'],
                'errors' => $alignment['errors'] ?? [],
                'warnings' => $alignment['warnings'] ?? [],
            ];
        }

        // 5. Build preview summary
        $sentenceTranslations = $payload['sentence_translations'] ?? [];
        $vocabularyItems = $payload['vocabulary_items'] ?? [];
        $phraseItems = $payload['phrase_items'] ?? [];
        $warnings = $payload['warnings'] ?? [];

        $summary = [
            'sentence_translation_count' => count($sentenceTranslations),
            'vocabulary_item_count' => count($vocabularyItems),
            'phrase_item_count' => count($phraseItems),
            'warning_count' => count($warnings),
        ];

        $samples = [
            'sentence_translations' => array_slice($sentenceTranslations, 0, 3),
            'vocabulary_items' => array_slice($vocabularyItems, 0, 3),
            'phrase_items' => array_slice($phraseItems, 0, 3),
            'warnings' => $warnings,
        ];

        return [
            'success' => true,
            'parsed' => true,
            'schema_version' => $payload['schema_version'] ?? '',
            'summary' => $summary,
            'items' => [
                'sentence_translations' => $sentenceTranslations,
                'vocabulary_items' => $vocabularyItems,
                'phrase_items' => $phraseItems,
                'warnings' => $warnings,
            ],
            'samples' => $samples,
            'errors' => [],
        ];
    }

    /**
     * Get the current saved AI reading assist data for a chapter.
     *
     * @return array
     */
    /**
     * Look up AI vocabulary and phrase suggestions for a specific word in a sentence.
     *
     * Matches by sentence_index + (surface OR lemma) for vocab items,
     * and by sentence_index + (trigger_words OR phrase) for phrase items.
     *
     * @return array
     */
    public function lookupSuggestions(int $userId, string $language, int $chapterId, string $word, string $lemma, int $sentenceIndex): array
    {
        $chapter = Chapter::where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            return ['success' => false, 'message' => '章节不存在或不属于当前用户。'];
        }

        $record = ChapterAiReadingAssist::where('user_id', $userId)
            ->where('language', $language)
            ->where('chapter_id', $chapterId)
            ->first();

        if (!$record) {
            return [
                'success' => true,
                'vocabulary_suggestions' => [],
                'phrase_suggestions' => [],
            ];
        }

        $vocabItems = $record->vocabulary_items ?? [];
        $phraseItems = $record->phrase_items ?? [];
        $wordLower = mb_strtolower(trim($word));
        $lemmaLower = mb_strtolower(trim($lemma));

        // Match vocabulary_items
        $vocabSuggestions = [];
        foreach ($vocabItems as $vi) {
            $viSi = $vi['sentence_index'] ?? null;
            if ($viSi === null || (int) $viSi !== $sentenceIndex) {
                continue;
            }
            $surface = mb_strtolower(trim($vi['surface'] ?? ''));
            $suggestedLemma = mb_strtolower(trim($vi['suggested_lemma'] ?? ''));
            $matched = ($surface === $wordLower) || ($surface === $lemmaLower)
                || ($suggestedLemma === $wordLower) || ($suggestedLemma === $lemmaLower);
            if ($matched) {
                $vocabSuggestions[] = [
                    'surface' => $vi['surface'] ?? '',
                    'suggested_lemma' => $vi['suggested_lemma'] ?? '',
                    'pos' => $vi['pos'] ?? '',
                    'meaning_zh' => $vi['meaning_zh'] ?? '',
                    'source_sentence' => $vi['source_sentence'] ?? '',
                    'reason' => $vi['reason'] ?? '',
                    'confidence' => $vi['confidence'] ?? 'medium',
                ];
            }
        }

        // Match phrase_items by trigger_words or phrase text
        $phraseSuggestions = [];
        foreach ($phraseItems as $pi) {
            $piSi = $pi['sentence_index'] ?? null;
            if ($piSi === null || (int) $piSi !== $sentenceIndex) {
                continue;
            }
            $triggerWords = array_map('mb_strtolower', array_map('trim', $pi['trigger_words'] ?? []));
            $phrase = mb_strtolower(trim($pi['phrase'] ?? ''));
            $matched = in_array($wordLower, $triggerWords) || in_array($lemmaLower, $triggerWords)
                || str_contains($phrase, $wordLower) || str_contains($phrase, $lemmaLower);
            if ($matched) {
                $phraseSuggestions[] = [
                    'phrase' => $pi['phrase'] ?? '',
                    'meaning_zh' => $pi['meaning_zh'] ?? '',
                    'source_sentence' => $pi['source_sentence'] ?? '',
                    'trigger_words' => $pi['trigger_words'] ?? [],
                    'reason' => $pi['reason'] ?? '',
                    'confidence' => $pi['confidence'] ?? 'medium',
                ];
            }
        }

        return [
            'success' => true,
            'vocabulary_suggestions' => $vocabSuggestions,
            'phrase_suggestions' => $phraseSuggestions,
        ];
    }

    public function getCurrentAssist(int $userId, string $language, int $chapterId): array
    {
        $chapter = Chapter::where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            return [
                'success' => false,
                'message' => '章节不存在或不属于当前用户。',
            ];
        }

        $record = ChapterAiReadingAssist::where('user_id', $userId)
            ->where('language', $language)
            ->where('chapter_id', $chapterId)
            ->first();

        if (!$record) {
            return [
                'success' => true,
                'chapter_id' => (int) $chapterId,
                'has_saved_assist' => false,
                'sentence_translations' => [],
                'summary' => null,
            ];
        }

        return [
            'success' => true,
            'chapter_id' => (int) $chapterId,
            'has_saved_assist' => true,
            'sentence_translations' => $record->sentence_translations ?? [],
            'summary' => $record->summary,
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Confirm and save the AI analysis result for a chapter.
     *
     * Re-validates the payload, then saves (or overwrites) the structured
     * AI reading assist data. Does NOT create WordSense, ReviewCard, or
     * ReviewLog.
     *
     * @return array
     */
    public function confirmImport(int $userId, string $language, int $chapterId, string $rawAiText): array
    {
        // 1. Verify chapter ownership
        $chapter = Chapter::where('id', $chapterId)
            ->where('user_id', $userId)
            ->where('language', $language)
            ->first();

        if (!$chapter) {
            return [
                'success' => false,
                'message' => '章节不存在或不属于当前用户。',
            ];
        }

        // 2. Extract JSON payload
        $extracted = $this->extractJsonPayload($rawAiText);
        if (!$extracted['success']) {
            return [
                'success' => false,
                'message' => $extracted['message'],
                'errors' => $extracted['errors'] ?? [],
            ];
        }

        $payload = $extracted['payload'];

        // 3. Re-validate payload (same validation as preview)
        $validation = $this->validatePayload($payload);
        if (!$validation['success']) {
            return [
                'success' => false,
                'message' => $validation['message'],
                'errors' => $validation['errors'] ?? [],
            ];
        }

        // 4. Validate sentence alignment (reject misaligned data)
        $alignment = $this->validateSentenceAlignment($payload, $chapter);
        if (!$alignment['success']) {
            return [
                'success' => false,
                'message' => $alignment['message'],
                'errors' => $alignment['errors'] ?? [],
                'warnings' => $alignment['warnings'] ?? [],
            ];
        }

        // 5. Build summary
        $sentenceTranslations = $payload['sentence_translations'] ?? [];
        $vocabularyItems = $payload['vocabulary_items'] ?? [];
        $phraseItems = $payload['phrase_items'] ?? [];
        $warnings = $payload['warnings'] ?? [];
        $schemaVersion = $payload['schema_version'] ?? '';

        $summary = [
            'sentence_translation_count' => count($sentenceTranslations),
            'vocabulary_item_count' => count($vocabularyItems),
            'phrase_item_count' => count($phraseItems),
            'warning_count' => count($warnings),
        ];

        // 5. Save — overwrite if already exists for this user+language+chapter
        ChapterAiReadingAssist::updateOrCreate(
            [
                'user_id' => $userId,
                'language' => $language,
                'chapter_id' => $chapterId,
            ],
            [
                'schema_version' => $schemaVersion,
                'sentence_translations' => $sentenceTranslations,
                'vocabulary_items' => $vocabularyItems,
                'phrase_items' => $phraseItems,
                'warnings' => $warnings,
                'summary' => $summary,
            ]
        );

        return [
            'success' => true,
            'chapter_id' => (int) $chapterId,
            'summary' => $summary,
            'message' => '本章 AI 辅助内容已保存。',
        ];
    }

    // ──────────────────────────────────────────────
    //  JSON Extraction
    // ──────────────────────────────────────────────

    /**
     * Extract a JSON payload from AI return text.
     *
     * Supports:
     * - Pure JSON
     * - ```json ... ``` code blocks
     * - ``` ... ``` code blocks (without language hint)
     * - Surrounding explanation text
     */
    public function extractJsonPayload(string $rawText): array
    {
        $trimmed = trim($rawText);

        if (empty($trimmed)) {
            return [
                'success' => false,
                'message' => 'AI 返回内容为空。',
                'errors' => [['field' => 'raw', 'message' => '没有内容可解析。']],
            ];
        }

        // Strategy 1: Try direct JSON parse first (fast path)
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return ['success' => true, 'payload' => $decoded];
        }

        // Strategy 2: Extract from ```json ... ``` block
        $jsonBlockPattern = '/```(?:json)?\s*([\s\S]*?)```/';
        if (preg_match($jsonBlockPattern, $trimmed, $matches)) {
            $jsonStr = trim($matches[1]);
            $decoded = json_decode($jsonStr, true);
            if (is_array($decoded)) {
                return ['success' => true, 'payload' => $decoded];
            }
        }

        // Strategy 3: Extract first { ... } object from text
        $firstBrace = strpos($trimmed, '{');
        $lastBrace = strrpos($trimmed, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidate = substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1);

            // Try trailing comma fix
            $fixed = preg_replace('/,\s*}/', '}', $candidate);
            $decoded = json_decode($fixed, true);
            if (is_array($decoded)) {
                return ['success' => true, 'payload' => $decoded];
            }

            // Try json_last_error() for detail
            json_decode($fixed);
            $errorMsg = $this->jsonLastErrorMessage();
            return [
                'success' => false,
                'message' => 'AI 返回内容包含 JSON 格式错误。',
                'errors' => [['field' => 'json', 'message' => $errorMsg]],
            ];
        }

        return [
            'success' => false,
            'message' => '没有识别出有效的 AI 返回格式。请确认 AI 只返回 JSON。',
            'errors' => [['field' => 'json', 'message' => '未找到 JSON 对象。']],
        ];
    }

    // ──────────────────────────────────────────────
    //  Payload Validation
    // ──────────────────────────────────────────────

    /**
     * Validate sentence alignment between AI payload and the chapter's real sentence list.
     *
     * Checks:
     * - Each sentence_translation sentence_index exists in the chapter's sentences.
     * - No duplicate sentence_index.
     * - Returns warnings for missing translations, errors for non-existent indexes.
     *
     * @return array{success: bool, message?: string, errors?: array, warnings?: array}
     */
    public function validateSentenceAlignment(array $payload, Chapter $chapter): array
    {
        $sentenceList = $this->buildSentenceList($chapter);
        if (empty($sentenceList)) {
            // Chapter not yet processed; cannot validate — allow through
            return ['success' => true];
        }

        // Build lookup: sentence_index => text
        $sentenceMap = [];
        foreach ($sentenceList as $s) {
            $sentenceMap[$s['sentence_index']] = $s['text'];
        }

        $translations = $payload['sentence_translations'] ?? [];
        $errors = [];
        $warnings = [];
        $seenIndexes = [];

        foreach ($translations as $st) {
            $si = $st['sentence_index'] ?? null;

            if ($si === null) {
                $errors[] = ['field' => 'sentence_translations', 'message' => '某条译文缺少 sentence_index。'];
                continue;
            }

            if (!isset($sentenceMap[$si])) {
                $errors[] = ['field' => 'sentence_translations', 'message' => "sentence_index {$si} 不存在于本章句子列表中。"];
                continue;
            }

            if (isset($seenIndexes[$si])) {
                $warnings[] = ['field' => 'sentence_translations', 'message' => "sentence_index {$si} 出现多次，已跳过重复。"];
                continue;
            }
            $seenIndexes[$si] = true;

            // Optional: check source_text roughly matches
            $sourceText = trim($st['source_text'] ?? '');
            $expectedText = $sentenceMap[$si];
            if ($sourceText !== '' && strcasecmp($sourceText, $expectedText) !== 0) {
                // Source text mismatch — warn but allow (AI may normalize spacing)
                $warnings[] = [
                    'field' => 'sentence_translations',
                    'message' => "sentence_index {$si} 的 source_text 与原文不一致。期望：「{$expectedText}」，收到：「{$sourceText}」",
                ];
            }
        }

        // Check for missing translations
        $missingIndexes = array_diff(array_keys($sentenceMap), array_keys($seenIndexes));
        if (!empty($missingIndexes)) {
            $missingStr = implode(', ', array_slice($missingIndexes, 0, 10));
            $warnings[] = [
                'field' => 'sentence_translations',
                'message' => '缺少以下 sentence_index 的译文：' . $missingStr . (count($missingIndexes) > 10 ? ' 等' : ''),
            ];
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'AI 返回的句子编号与本章句子列表不匹配。',
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return [
            'success' => true,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate the parsed payload against expected schema.
     *
     * @return array{success: bool, message?: string, errors?: array}
     */
    public function validatePayload(array $payload): array
    {
        $errors = [];

        // schema_version
        if (empty($payload['schema_version'])) {
            $errors[] = ['field' => 'schema_version', 'message' => '缺少 schema_version 字段。'];
        } elseif ($payload['schema_version'] !== 'linguacafe_ai_reading_assist_v1') {
            $errors[] = ['field' => 'schema_version', 'message' => "不支持的 schema 版本：{$payload['schema_version']}，应为 linguacafe_ai_reading_assist_v1。"];
        }

        // Top-level required fields
        $requiredFields = ['sentence_translations', 'vocabulary_items', 'phrase_items', 'warnings'];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $payload)) {
                $errors[] = ['field' => $field, 'message' => "缺少 {$field} 字段。"];
            } elseif (!is_array($payload[$field])) {
                $errors[] = ['field' => $field, 'message' => "{$field} 必须是一个数组。"];
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'AI 返回内容缺少必要字段。',
                'errors' => $errors,
            ];
        }

        return ['success' => true];
    }

    // ──────────────────────────────────────────────
    //  Prompt Builder
    // ──────────────────────────────────────────────

    private function buildPromptText(string $articleText, array $sentenceList = []): string
    {
        $schemaVersion = 'linguacafe_ai_reading_assist_v1';

        // Build the sentence list block
        $sentenceListBlock = '';
        if (!empty($sentenceList)) {
            $listJson = json_encode($sentenceList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            // Indent each line for readability within the prompt
            $indented = preg_replace('/^/m', '  ', $listJson);
            $sentenceListBlock = <<<SENTENCES

## LinguaCafe 句子列表

你必须严格使用下面给出的 sentence_index。
不要自己重新切句。不要自己重新编号。
sentence_translations 中的 sentence_index 必须来自下面列表。
vocabulary_items 和 phrase_items 中的 sentence_index 也必须来自下面列表。

{$indented}

SENTENCES;
        }

        return <<<PROMPT
你是一个英语阅读辅助分析器。你的任务是根据给定的英文文章，生成结构化的中文辅助阅读数据。

## 任务要求

1. 只分析给定的英文文章。
2. 不补充文章外内容。
3. 不发表评论或解释。{$sentenceListBlock}

## 输出格式

1. 只返回 JSON。
2. 不返回 Markdown。
3. 不返回代码块标记（不要用 ```json）。
4. 不返回任何解释文字。
5. 字段名必须完全按照下面的 schema。
6. 缺内容用空数组 [] 或空字符串 ""，不要省略字段。
7. 不要输出 trailing comma。
8. 不要输出注释。

## JSON Schema

{
  "schema_version": "{$schemaVersion}",
  "language": "english",
  "source": {
    "chapter_title": "",
    "word_count_estimate": 0
  },
  "sentence_translations": [
    {
      "sentence_index": 1,
      "source_text": "",
      "translation_zh": ""
    }
  ],
  "vocabulary_items": [
    {
      "surface": "",
      "suggested_lemma": "",
      "pos": "",
      "sentence_index": 1,
      "source_sentence": "",
      "meaning_zh": "",
      "reason": "",
      "confidence": "high"
    }
  ],
  "phrase_items": [
    {
      "phrase": "",
      "sentence_index": 1,
      "source_sentence": "",
      "meaning_zh": "",
      "trigger_words": [],
      "reason": "",
      "confidence": "high"
    }
  ],
  "warnings": [
    {
      "type": "",
      "message": ""
    }
  ]
}

## 翻译要求

1. 每个英文句子给一个中文译文。
2. 译文与英文句子一一对应。
3. 译文放在 sentence_translations 列表中。
4. source_text 必须复制句子列表中的原文，不要修改，不要缩写。

## 生词要求

1. 只列出对中文读者可能造成困难的词（中等以上难度）。
2. 每个词只给本语境中的一种中文释义。
3. 生词放在 vocabulary_items 列表中。
4. 不列出过于基础的词（如 "the", "a", "is", "are", "have" 等）。
5. vocabulary_items 的 sentence_index 必须来自句子列表。

## 词组要求

1. 识别固定搭配、短语动词、非字面表达。
2. 每个词组只给本语境中的一种中文释义。
3. 词组放在 phrase_items 列表中。
4. trigger_words 建议列出词组中用户点击后应该触发提示的关键词。
5. phrase_items 的 sentence_index 必须来自句子列表。

## 输入文章

ARTICLE_TEXT_START
{$articleText}
ARTICLE_TEXT_END
PROMPT;
    }

    /**
     * Clean raw_text: normalize line endings, remove excessive whitespace.
     */
    private function cleanRawText(string $rawText): string
    {
        // Normalize line endings
        $text = str_replace("\r\n", "\n", $rawText);
        $text = str_replace("\r", "\n", $text);

        // Remove " NEWLINE " markers (from the editor format)
        $text = str_replace(" NEWLINE ", "\n", $text);

        // Collapse multiple blank lines into one
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Get a human-readable JSON last error message.
     */
    private function jsonLastErrorMessage(): string
    {
        return match (json_last_error()) {
            JSON_ERROR_DEPTH => 'JSON 嵌套层数超过限制。',
            JSON_ERROR_STATE_MISMATCH => 'JSON 格式不完整或格式错误。',
            JSON_ERROR_CTRL_CHAR => 'JSON 包含控制字符。',
            JSON_ERROR_SYNTAX => 'JSON 语法错误。',
            JSON_ERROR_UTF8 => 'JSON 包含非法 UTF-8 字符。',
            JSON_ERROR_RECURSION => 'JSON 包含递归引用。',
            JSON_ERROR_INF_OR_NAN => 'JSON 包含 INF 或 NaN。',
            JSON_ERROR_UNSUPPORTED_TYPE => 'JSON 包含不支持的类型。',
            JSON_ERROR_INVALID_PROPERTY_NAME => 'JSON 属性名无效。',
            JSON_ERROR_UTF16 => 'JSON 包含非法 UTF-16 字符。',
            default => '未知 JSON 解析错误。',
        };
    }
}
