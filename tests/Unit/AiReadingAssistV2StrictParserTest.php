<?php

namespace Tests\Unit;

use App\Services\AiReadingAssistV2ContractService;
use Tests\TestCase;

/**
 * V2 strict JSON parser contract tests（Phase A1）。
 *
 * 冻结语义（PA-R1-AI-CONTRACT-20260807-2302 §6/§7/§10 与测试矩阵 D/E/F 组）：
 *  - V2 只接受直接完整 JSON，不接受 code fence / 外围文字 / trailing comma 修复（与 V1 宽松解析隔离）；
 *  - result ∈ {matched_existing, new_sense, ambiguous}；confidence ∈ {high, medium, low}；
 *  - occurrence_id 必须来自 server manifest（缺/重复/自造均拒绝）；identity echo 必须与 server 一致；
 *  - source_revision 必须匹配当前 server chapter revision（stale 拒绝）。
 *
 * RED-by-contract：AiReadingAssistV2ContractService 由 Lane1（Backend Core）实现。
 * 本类按 AC 冻结的职责引用其 validateAiResponse() 入口；Lane1 落地后自动转 GREEN，
 * 如方法命名有调整，仅需对齐调用点，不得放宽断言语义。
 */
class AiReadingAssistV2StrictParserTest extends TestCase
{
    private AiReadingAssistV2ContractService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(AiReadingAssistV2ContractService::class)) {
            $this->markTestIncomplete(
                'PENDING_INTEGRATION_RUN: Lane1 尚未实现 App\Services\AiReadingAssistV2ContractService，'
                . 'Lane4 合并 Backend Core 后必须执行全部 strict-parser assertions。'
            );
        }

        $this->service = app(AiReadingAssistV2ContractService::class);
    }

    private function fixtures(): array
    {
        return require base_path('tests/Fixtures/ai-assist-v2/v2-payloads.php');
    }

    /**
     * 调用未来 V2 contract service 的 strict validation 入口。
     * 语义（AC §10 错误码表）：返回 array{success: bool, errors?: array}；
     * 任何一条规则违反都必须 success=false，不允许部分放行。
     */
    private function validateStrict(array|string $payload): array
    {
        return $this->service->validateAiResponse($payload);
    }

    public function test_v2_accepts_direct_valid_json(): void
    {
        $result = $this->validateStrict($this->fixtures()['valid']);
        $this->assertTrue($result['success']);
    }

    public function test_v2_rejects_code_fence(): void
    {
        $result = $this->validateStrict($this->fixtures()['code_fence']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_surrounding_prose(): void
    {
        $result = $this->validateStrict($this->fixtures()['surrounding_prose']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_trailing_comma(): void
    {
        $result = $this->validateStrict($this->fixtures()['trailing_comma']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_wrong_schema(): void
    {
        $result = $this->validateStrict($this->fixtures()['wrong_schema']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_missing_required_item(): void
    {
        $result = $this->validateStrict($this->fixtures()['missing_required_item']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_invalid_confidence(): void
    {
        $result = $this->validateStrict($this->fixtures()['invalid_confidence']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_invalid_result(): void
    {
        $result = $this->validateStrict($this->fixtures()['invalid_result']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_duplicate_occurrence_id(): void
    {
        $result = $this->validateStrict($this->fixtures()['duplicate_occurrence_id']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_missing_occurrence(): void
    {
        $result = $this->validateStrict($this->fixtures()['missing_occurrence']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_extra_occurrence(): void
    {
        $result = $this->validateStrict($this->fixtures()['extra_occurrence']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_identity_echo_mismatch(): void
    {
        $result = $this->validateStrict($this->fixtures()['identity_echo_mismatch']);
        $this->assertFalse($result['success']);
    }

    public function test_v2_rejects_stale_source_revision(): void
    {
        $result = $this->validateStrict($this->fixtures()['stale_source_revision']);
        $this->assertFalse($result['success']);
    }
}
