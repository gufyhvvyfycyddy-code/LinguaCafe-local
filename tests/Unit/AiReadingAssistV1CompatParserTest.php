<?php

namespace Tests\Unit;

use App\Services\AiReadingAssistService;
use Tests\TestCase;

/**
 * V1 宽松 JSON 解析兼容护栏（Phase A5 / V1 compatibility）。
 *
 * 冻结语义（PA-R1-AI-CONTRACT-20260807-2302 §3.3、§6 与 §21.5）：
 *  - V1 允许 code fence / 外围说明文字 / trailing comma 修复（历史兼容保留）；
 *  - V1 只校验顶层 4 字段存在且为数组，不逐 item 校验；
 *  - V2 strict parser 不得继承这些"修复"行为（本类只锁 V1 侧，V2 侧见
 *    AiReadingAssistV2StrictParserTest）。
 *
 * 纯函数测试：extractJsonPayload / validatePayload 不触 DB，可在本 worktree 运行（GREEN）。
 */
class AiReadingAssistV1CompatParserTest extends TestCase
{
    private AiReadingAssistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AiReadingAssistService::class);
    }

    private function v1Payload(): array
    {
        return [
            'schema_version' => 'linguacafe_ai_reading_assist_v1',
            'sentence_translations' => [
                ['sentence_index' => 1, 'source_text' => 'This is a test.', 'translation_zh' => '这是一个测试。'],
            ],
            'vocabulary_items' => [
                ['surface' => 'test', 'suggested_lemma' => 'test', 'pos' => 'NOUN', 'sentence_index' => 1, 'source_sentence' => 'This is a test.', 'meaning_zh' => '测试', 'confidence' => 'high'],
            ],
            'phrase_items' => [],
            'warnings' => [],
        ];
    }

    public function test_v1_accepts_direct_json(): void
    {
        $result = $this->service->extractJsonPayload(json_encode($this->v1Payload(), JSON_UNESCAPED_UNICODE));
        $this->assertTrue($result['success']);
        $this->assertSame('linguacafe_ai_reading_assist_v1', $result['payload']['schema_version']);
    }

    public function test_v1_accepts_code_fence(): void
    {
        $wrapped = "```json\n" . json_encode($this->v1Payload(), JSON_UNESCAPED_UNICODE) . "\n```";
        $result = $this->service->extractJsonPayload($wrapped);
        $this->assertTrue($result['success']);
    }

    public function test_v1_accepts_surrounding_prose(): void
    {
        $wrapped = "以下是 AI 分析结果：\n" . json_encode($this->v1Payload(), JSON_UNESCAPED_UNICODE) . "\n以上就是全部内容。";
        $result = $this->service->extractJsonPayload($wrapped);
        $this->assertTrue($result['success']);
    }

    public function test_v1_fixes_trailing_comma(): void
    {
        $json = json_encode($this->v1Payload(), JSON_UNESCAPED_UNICODE);
        // V1 的既有修复只处理 object 末尾的 trailing comma（`,}`）。
        $withTrailingComma = substr($json, 0, -1) . ',}';
        $result = $this->service->extractJsonPayload($withTrailingComma);
        // V1 兼容：trailing comma 被修复并成功解析（AC §3.3 记录该历史行为）
        $this->assertTrue($result['success']);
    }

    public function test_v1_validate_accepts_v1_schema(): void
    {
        $result = $this->service->validatePayload($this->v1Payload());
        $this->assertTrue($result['success']);
    }

    public function test_v1_validate_rejects_v2_schema_version(): void
    {
        $payload = $this->v1Payload();
        $payload['schema_version'] = 'linguacafe_ai_reading_assist_v2';
        $result = $this->service->validatePayload($payload);
        // V1 路径不能接受 V2 schema（V2 走专门 contract service）
        $this->assertFalse($result['success']);
    }

    public function test_v1_validate_rejects_missing_top_level_field(): void
    {
        $payload = $this->v1Payload();
        unset($payload['phrase_items']);
        $result = $this->service->validatePayload($payload);
        $this->assertFalse($result['success']);
    }
}
