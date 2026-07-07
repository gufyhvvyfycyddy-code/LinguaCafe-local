<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiStudyCardV6RequestPackageUiGuardTest extends TestCase
{
    private string $workflowPath;
    private string $previewDialogPath;
    private string $v6PanelPath;
    private string $workflowServicePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workflowPath = resource_path('js/components/Text/AiStudyCardDesktopWorkflow.vue');
        $this->previewDialogPath = resource_path('js/components/Text/AiStudyCardPreviewDialog.vue');
        $this->v6PanelPath = resource_path('js/components/Text/AiStudyCardV6RequestPackagePanel.vue');
        $this->workflowServicePath = resource_path('js/services/AiStudyCardPendingWorkflowService.js');
    }

    public function test_v6_request_package_panel_exists_and_is_mounted_by_preview_dialog(): void
    {
        $this->assertFileExists($this->v6PanelPath);

        $preview = file_get_contents($this->previewDialogPath);
        $this->assertStringContainsString("import AiStudyCardV6RequestPackagePanel from './AiStudyCardV6RequestPackagePanel.vue'", $preview);
        $this->assertStringContainsString('AiStudyCardV6RequestPackagePanel,', $preview);
        $this->assertStringContainsString('<AiStudyCardV6RequestPackagePanel :selected-item-ids="selectedItemIds" />', $preview);
    }

    public function test_v6_panel_copy_and_safety_copy_are_visible(): void
    {
        $contents = file_get_contents($this->v6PanelPath);

        $requiredTexts = [
            'V6 请求包（不调用 AI）',
            'provider disabled',
            '不会调用真实 AI',
            '不会生成 WordSense / ReviewCard',
            '不会写 ReviewLog',
            '不会改 FSRS',
            '复制 V6 请求包',
            '这是 provider-disabled 请求包，不是 AI 输出，也不会生成学习卡。',
        ];

        foreach ($requiredTexts as $text) {
            $this->assertStringContainsString($text, $contents, "V6 request-package UI must show safety text: {$text}");
        }
    }

    public function test_v6_panel_calls_only_local_request_package_service(): void
    {
        $panel = file_get_contents($this->v6PanelPath);
        $service = file_get_contents($this->workflowServicePath);

        $this->assertStringContainsString('buildV6RequestPackage', $panel);
        $this->assertStringContainsString('copyTextToClipboard', $panel);
        $this->assertStringContainsString("axios.post('/ai-study-card/v6/recommendations/request-package'", $service);
        $this->assertStringContainsString('export function buildV6RequestPackage', $service);
    }

    public function test_v6_ui_does_not_expand_main_workflow_state_or_methods(): void
    {
        $workflow = file_get_contents($this->workflowPath);

        $forbiddenV6State = [
            'aiV6RequestPackage',
            'aiV6RequestPackageLoading',
            'aiV6RequestPackageError',
            'generateV6RequestPackage()',
            'copyV6RequestPackage()',
            'buildV6RequestPackage(axios',
        ];

        foreach ($forbiddenV6State as $fingerprint) {
            $this->assertStringNotContainsString($fingerprint, $workflow, "V6 request-package state/method must not be added to AiStudyCardDesktopWorkflow: {$fingerprint}");
        }
    }

    public function test_v6_ui_has_no_real_provider_or_key_material(): void
    {
        $paths = [$this->v6PanelPath, $this->previewDialogPath, $this->workflowServicePath];
        $forbidden = [
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.x.ai',
            'OPENAI_API_KEY',
            'DEEPSEEK_API_KEY',
            'ANTHROPIC_API_KEY',
            'GEMINI_API_KEY',
            'localStorage',
            'sessionStorage',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, basename($path) . " must not expose provider/key material: {$needle}");
            }
        }
    }

    public function test_v6_panel_does_not_create_cards_or_review_logs(): void
    {
        $contents = file_get_contents($this->v6PanelPath);

        $forbidden = [
            '/ai-study-card/generate-cards',
            '/reviews/rate',
            '/reviews/senses/',
            'ReviewLog::',
            'recordReview(',
            'fsrs_state',
            'target_type: \'word\'',
            'target_type: "word"',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $contents, "V6 request-package panel must not create cards or ratings: {$needle}");
        }
    }
}
