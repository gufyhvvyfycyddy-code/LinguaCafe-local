<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiStudyCardV6RequestPackageUiGuardTest extends TestCase
{
    private string $workflowPath;
    private string $previewDialogPath;
    private string $v6PanelPath;
    private string $recommendationPanelPath;
    private string $workflowServicePath;
    private string $recommendationParserPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workflowPath = resource_path('js/components/Text/AiStudyCardDesktopWorkflow.vue');
        $this->previewDialogPath = resource_path('js/components/Text/AiStudyCardPreviewDialog.vue');
        $this->v6PanelPath = resource_path('js/components/Text/AiStudyCardV6RequestPackagePanel.vue');
        $this->recommendationPanelPath = resource_path('js/components/Text/AiStudyCardRecommendationPanel.vue');
        $this->workflowServicePath = resource_path('js/services/AiStudyCardPendingWorkflowService.js');
        $this->recommendationParserPath = resource_path('js/services/AiStudyCardRecommendationParserService.js');
    }

    public function test_v6_request_package_panel_exists_and_is_mounted_by_preview_dialog(): void
    {
        $this->assertFileExists($this->v6PanelPath);

        $preview = file_get_contents($this->previewDialogPath);
        $this->assertStringContainsString("import AiStudyCardV6RequestPackagePanel from './AiStudyCardV6RequestPackagePanel.vue'", $preview);
        $this->assertStringContainsString('AiStudyCardV6RequestPackagePanel,', $preview);
        $this->assertStringContainsString('<AiStudyCardV6RequestPackagePanel', $preview);
        $this->assertStringContainsString(':selected-item-ids="selectedItemIds"', $preview);
        $this->assertStringContainsString('@apply-recommendations', $preview);
    }

    public function test_v6_panel_copy_and_safety_copy_are_visible(): void
    {
        $contents = file_get_contents($this->v6PanelPath);

        $requiredTexts = [
            'V6 请求包（不调用 AI）',
            'provider disabled',
            '调用 V6 AI 推荐（后端预览）',
            '浏览器只调用本地后端',
            '推荐结果默认不勾选',
            '不会生成 WordSense / ReviewCard',
            '不会写 ReviewLog',
            '不会改 FSRS',
            '复制 V6 请求包',
            '复制 V6 AI 推荐预览',
            '导入到 AI 推荐词列表（默认不勾选）',
            '自动丢弃',
            'AI 本次没有找到新的可加入候选词，重复项已自动丢弃。你可以换一组待解释词再试。',
            '不会自动勾选',
            '不会自动生成最终候选包',
            '这是 AI 生成的候选建议，默认不勾选',
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
        $this->assertStringContainsString('buildV6ProviderPreview', $panel);
        $this->assertStringContainsString('$emit(\'apply-recommendations\', recommendationPackage)', $panel);
        $this->assertStringContainsString('recommendedItemCount()', $panel);
        $this->assertStringContainsString('droppedItemCount()', $panel);
        $this->assertStringContainsString('allRecommendationsDropped()', $panel);
        $this->assertStringContainsString('copyTextToClipboard', $panel);
        $this->assertStringContainsString("axios.post('/ai-study-card/v6/recommendations/request-package'", $service);
        $this->assertStringContainsString("axios.post('/ai-study-card/v6/recommendations/provider-preview'", $service);
        $this->assertStringContainsString('export function buildV6RequestPackage', $service);
        $this->assertStringContainsString('export function buildV6ProviderPreview', $service);
    }

    public function test_v6_ui_does_not_expand_main_workflow_state_or_methods(): void
    {
        $workflow = file_get_contents($this->workflowPath);

        $forbiddenV6State = [
            'aiV6RequestPackage',
            'aiV6RequestPackageLoading',
            'aiV6RequestPackageError',
            'aiV6ProviderPreview',
            'aiV6ProviderPreviewLoading',
            'generateV6RequestPackage()',
            'copyV6RequestPackage()',
            'generateV6ProviderPreview()',
            'buildV6RequestPackage(axios',
            'buildV6ProviderPreview(axios',
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

    public function test_v6_recommendations_flow_into_existing_v4_list_default_unchecked(): void
    {
        $workflow = file_get_contents($this->workflowPath);
        $preview = file_get_contents($this->previewDialogPath);
        $panel = file_get_contents($this->v6PanelPath);
        $parser = file_get_contents($this->recommendationParserPath);

        $this->assertStringContainsString('@apply-v6-recommendations="applyV6Recommendations"', $workflow);
        $this->assertStringContainsString('@apply-recommendations="$emit(\'apply-v6-recommendations\', $event)"', $preview);
        $this->assertStringContainsString('$emit(\'apply-recommendations\', recommendationPackage)', $panel);
        $this->assertStringContainsString('applyV6Recommendations(recommendationPackage)', $workflow);
        $this->assertStringContainsString('importV6Recommendations(recommendationPackage', $workflow);
        $this->assertStringContainsString('JSON.stringify(recommendationPackage || {}, null, 2)', $parser);
        $this->assertStringContainsString('parseAiRecommendations(jsonInput, pendingItems, selectedIds)', $parser);
        $this->assertStringContainsString('selectedIndices: []', $parser);
        $this->assertStringContainsString('aiRecommendationImportNotice', $workflow);
        $this->assertStringContainsString('已从 V6 AI 推荐预览导入', $parser);
        $this->assertStringContainsString('最终生成学习卡前仍必须填写中文释义', $parser);
        $this->assertStringNotContainsString('selectAllAiRecommendations();', $workflow);
        $this->assertStringNotContainsString('generateFinalCandidatesPackage();', $workflow);
        $this->assertStringNotContainsString('openGenerateCardsDialog();', $workflow);
    }

    public function test_v6_import_notice_guides_user_to_manual_v5_confirmation(): void
    {
        $workflow = file_get_contents($this->workflowPath);
        $preview = file_get_contents($this->previewDialogPath);
        $recommendationPanel = file_get_contents($this->recommendationPanelPath);
        $parser = file_get_contents($this->recommendationParserPath);

        $this->assertStringContainsString(':ai-recommendation-import-notice="aiRecommendationImportNotice"', $workflow);
        $this->assertStringContainsString(':import-notice="aiRecommendationImportNotice"', $preview);
        $this->assertStringContainsString('importNotice: { type: String, default: \'\' }', $recommendationPanel);
        $this->assertStringContainsString('v-if="importNotice"', $recommendationPanel);
        $this->assertStringContainsString('已从 V6 AI 推荐预览导入', $parser);
        $this->assertStringContainsString('默认未勾选', $parser);
        $this->assertStringContainsString('手动勾选', $parser);
        $this->assertStringContainsString('最终生成学习卡前仍必须填写中文释义', $parser);
        $this->assertStringNotContainsString('sense_zh: item.reason', $workflow);
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
