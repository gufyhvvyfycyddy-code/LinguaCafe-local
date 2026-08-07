<?php

/**
 * tests/Fixtures/ai-assist-v2/v2-payloads.php
 *
 * 固定 AI Reading Assist V2 wire payload fixtures —— 冻结于 PA-R1-AI-CONTRACT-20260807-2302
 * （wire response schema: AC §6; result/confidence 合法组合: AC §7; occurrence identity: AC §4.2）。
 *
 * 用途（Lane 3 Safety Harness）：
 *  - 供 Unit strict-parser contract tests 与 Feature 行为 tests 共用同一份"模拟外部 AI 返回"，
 *    不调用真实 AI provider（AC:1135 要求固定 fixture）。
 *  - 各变体刻意只改一个维度，方便断言"哪个规则被违反"。
 *
 * 注意：occurrence_id 与 source_revision 均为 server-owned 值；测试中必须用服务端生成的合法值，
 * 不能用本 fixture 的占位字符串冒充 server truth（AC:154-155）。
 *
 * @return array<string, mixed>
 */

$base = [
    'schema_version' => 'linguacafe_ai_reading_assist_v2',
    'package_id' => 'pkg-v2-00000000-0000-4000-8000-000000000001',
    'source_revision' => 'sha256:fixture-source-revision-placeholder',
    'part_index' => 1,
    'part_count' => 1,
    'sentence_translations' => [
        ['sentence_index' => 0, 'source_text' => 'The banks of the river were crowded.', 'translation_zh' => '河岸上挤满了人。'],
    ],
    'word_results' => [
        [
            'occurrence_id' => 'occ2_fixture-word-target-0001',
            'surface' => 'banks',
            'lemma' => 'bank',
            'pos' => 'NOUN',
            'sentence_index' => 0,
            'source_sentence' => 'The banks of the river were crowded.',
            'result' => 'matched_existing',
            'matched_word_sense_id' => 81,
            'new_sense' => null,
            'confidence' => 'high',
            'reason' => '这里指河流两侧的陆地。',
        ],
    ],
    'phrase_results' => [
        [
            'occurrence_id' => 'occ2_fixture-phrase-target-0001',
            'phrase' => 'in light of',
            'sentence_index' => 0,
            'source_sentence' => 'In light of the evidence, we agree.',
            'sense_zh' => '鉴于；考虑到',
            'sense_en' => 'considering or because of something',
            'confidence' => 'medium',
            'reason' => '固定表达。',
        ],
    ],
    'warnings' => [],
];

return [
    'base' => $base,

    // —— V2 strict parse：合法基线 ——
    'valid' => $base,

    // code fence 包裹（V1 兼容可接受，V2 strict 必须拒绝）
    'code_fence' => "```json\n" . json_encode($base, JSON_UNESCAPED_UNICODE) . "\n```",

    // 外围说明文字（V1 可剥离，V2 strict 必须拒绝）
    'surrounding_prose' => "以下是 AI 分析结果：\n" . json_encode($base, JSON_UNESCAPED_UNICODE) . "\n以上就是全部内容。",

    // trailing comma（V1 会修复，V2 strict 必须拒绝且不修复）
    'trailing_comma' => (function () use ($base) {
        $json = json_encode($base, JSON_UNESCAPED_UNICODE);
        return preg_replace('/\}\]/', '},]}', $json);
    })(),

    // wrong schema（顶层字段名/结构错误）
    'wrong_schema' => [
        'schema_version' => 'linguacafe_ai_reading_assist_v2',
        'package_id' => 'pkg-v2-00000000-0000-4000-8000-000000000002',
        'source_revision' => 'sha256:fixture-source-revision-placeholder',
        'part_index' => 1,
        'part_count' => 1,
        'translations' => [],       // 错误字段名：应为 sentence_translations
        'word_results' => [],
        'phrase_results' => [],
        'warnings' => [],
    ],

    // missing required item（缺 word_results）
    'missing_required_item' => (function () use ($base) {
        $p = $base;
        unset($p['word_results']);
        return $p;
    })(),

    // confidence 非法值
    'invalid_confidence' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['confidence'] = 'very_high';
        return $p;
    })(),

    // result 非法值
    'invalid_result' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['result'] = 'ignore';
        return $p;
    })(),

    // duplicate occurrence id（word_results 内重复）
    'duplicate_occurrence_id' => (function () use ($base) {
        $p = $base;
        $p['word_results'][] = $p['word_results'][0];
        return $p;
    })(),

    // missing occurrence（part set 中漏掉一个 server 目标）
    'missing_occurrence' => (function () use ($base) {
        $p = $base;
        array_pop($p['word_results']);
        return $p;
    })(),

    // extra / self-made occurrence（AI 自造的 ID 不在 server manifest）
    'extra_occurrence' => (function () use ($base) {
        $p = $base;
        $p['word_results'][] = [
            'occurrence_id' => 'occ2_ai-made-up-id-not-from-server',
            'surface' => 'madeup',
            'lemma' => 'madeup',
            'pos' => 'NOUN',
            'sentence_index' => 0,
            'source_sentence' => 'The banks of the river were crowded.',
            'result' => 'ambiguous',
            'matched_word_sense_id' => null,
            'new_sense' => null,
            'confidence' => 'low',
            'reason' => 'AI 自造。',
        ];
        return $p;
    })(),

    // identity echo mismatch（surface/lemma/pos 与 server canonical 不符）
    'identity_echo_mismatch' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['lemma'] = 'bankk'; // 拼写错误
        return $p;
    })(),

    // —— candidate ownership ——
    'matched_null_id' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['matched_word_sense_id'] = null;
        $p['word_results'][0]['result'] = 'matched_existing';
        return $p;
    })(),

    'matched_candidate_outside_set' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['matched_word_sense_id'] = 999999; // 不在 server candidate set
        return $p;
    })(),

    'matched_with_new_sense' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['new_sense'] = [
            'sense_zh' => '河岸',
            'sense_en' => 'the land along the side of a river',
            'pos' => 'NOUN',
        ];
        return $p;
    })(),

    'new_sense_with_matched_id' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['result'] = 'new_sense';
        $p['word_results'][0]['matched_word_sense_id'] = 81; // 非法：new_sense 必须 null matched id
        $p['word_results'][0]['new_sense'] = [
            'sense_zh' => '河岸',
            'sense_en' => 'the land along the side of a river',
            'pos' => 'NOUN',
        ];
        return $p;
    })(),

    'ambiguous_with_matched_id' => (function () use ($base) {
        $p = $base;
        $p['word_results'][0]['result'] = 'ambiguous';
        $p['word_results'][0]['matched_word_sense_id'] = 81; // 非法：ambiguous 必须 null
        $p['word_results'][0]['new_sense'] = null;
        return $p;
    })(),

    'phrase_with_word_sense_id' => (function () use ($base) {
        $p = $base;
        $p['phrase_results'][0]['matched_word_sense_id'] = 81; // 非法：phrase 无 WordSense resolution
        return $p;
    })(),

    // —— stale source revision（与 server 当前 chapter revision 不一致）——
    'stale_source_revision' => (function () use ($base) {
        $p = $base;
        $p['source_revision'] = 'sha256:stale-revision-from-old-package';
        return $p;
    })(),

    // —— batching ——
    'part2_with_translations' => (function () use ($base) {
        $p = $base;
        $p['part_index'] = 2;
        $p['part_count'] = 2;
        // part 2+ 的 sentence_translations 必须严格为 []（AC:522-525）
        return $p;
    })(),
];
