<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4
 *
 * Deep-module guard tests for the AiStudyCardDesktopWorkflow split.
 *
 * Previous round (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2)
 * converged the V1-V5 desktop workflow into a single feature island
 * component AiStudyCardDesktopWorkflow.vue (~1020 lines). That eliminated
 * duplication between VocabularySideBox.vue and VocabularyBox.vue but created
 * a new "big ball of mud": one component carried V1 pending button, V2/V3
 * pending list dialog, V3 preview package, V4 AI recommendation paste/parse,
 * V4 final candidates package, V5 generate-cards dialog, V5 result panel,
 * clipboard DOM fallback, and ~25 data fields + 22 methods.
 *
 * This round (Round 4) splits the workflow into a thin container +
 * presentational sub-components:
 *   - AiStudyCardDesktopWorkflow.vue (container/coordinator, < 450 lines)
 *   - AiStudyCardPendingListDialog.vue (V3 pending/dismissed list dialog)
 *   - AiStudyCardPreviewDialog.vue (V3-V5 preview dialog, composes the panels)
 *   - AiStudyCardRecommendationPanel.vue (V4 AI recommendation paste/parse/list)
 *   - AiStudyCardPackagePanel.vue (V3/V4 JSON package display + copy button)
 *   - AiStudyCardClipboardService.js (clipboard helper with DOM fallback)
 *
 * These guards lock the deep-module boundary so a future edit cannot silently
 * re-introduce the big-ball-of-mud pattern (inline pending list template,
 * inline recommendation panel, inline package JSON, inline clipboard DOM
 * fallback) back into the container component.
 *
 * Covers (27 items from the task spec):
 *  1. AiStudyCardDesktopWorkflow.vue exists;
 *  2. AiStudyCardPendingListDialog.vue exists;
 *  3. AiStudyCardPreviewDialog.vue exists;
 *  4. AiStudyCardRecommendationPanel.vue exists;
 *  5. AiStudyCardPackagePanel.vue exists;
 *  6. AiStudyCardClipboardService.js exists;
 *  7. AiStudyCardDesktopWorkflow references the sub-components;
 *  8. Workflow no longer contains pending list template fingerprints;
 *  9. Workflow no longer contains AI recommendation panel fingerprints;
 * 10. Workflow no longer contains package panel fingerprints;
 * 11. Workflow no longer contains clipboard DOM fallback;
 * 12. AiStudyCardClipboardService contains clipboard fallback;
 * 13. AiStudyCardPendingListDialog does NOT call axios;
 * 14. AiStudyCardPreviewDialog does NOT call axios;
 * 15. AiStudyCardRecommendationPanel does NOT call axios;
 * 16. AiStudyCardPackagePanel does NOT call axios;
 * 17. Sub-components do NOT import Vuex / mapState;
 * 18. Parser service still guarantees AI recommendations default to UNSELECTED;
 * 19. Generate-cards service still initializes sense_zh as empty;
 * 20. Generate-cards dialog still shows sense_zh required + sense_en optional;
 * 21. VocabularySideBox still mounts AiStudyCardDesktopWorkflow;
 * 22. VocabularyBox still mounts AiStudyCardDesktopWorkflow;
 * 23. VocabularySideBoxChineseTextIntegrityTest still exists (regression guard);
 * 24. No external AI provider strings anywhere in the deep-module surface;
 * 25. No ReviewLog / FSRS rating / legacy word card creation calls;
 * 26. VocabularyBottomSheet does NOT contain AIStudyCard V5 workflow;
 * 27. Line-count guard: AiStudyCardDesktopWorkflow.vue must not exceed 450 lines.
 */
class AiStudyCardDesktopWorkflowDeepModuleGuardTest extends TestCase
{
    private string $workflowPath;
    private string $pendingListDialogPath;
    private string $previewDialogPath;
    private string $recommendationPanelPath;
    private string $packagePanelPath;
    private string $clipboardServicePath;
    private string $parserServicePath;
    private string $generateServicePath;
    private string $dialogPath;
    private string $sideBoxPath;
    private string $boxPath;
    private string $bottomSheetPath;
    private string $chineseTextIntegrityTestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflowPath = resource_path('js/components/Text/AiStudyCardDesktopWorkflow.vue');
        $this->pendingListDialogPath = resource_path('js/components/Text/AiStudyCardPendingListDialog.vue');
        $this->previewDialogPath = resource_path('js/components/Text/AiStudyCardPreviewDialog.vue');
        $this->recommendationPanelPath = resource_path('js/components/Text/AiStudyCardRecommendationPanel.vue');
        $this->packagePanelPath = resource_path('js/components/Text/AiStudyCardPackagePanel.vue');
        $this->clipboardServicePath = resource_path('js/services/AiStudyCardClipboardService.js');
        $this->parserServicePath = resource_path('js/services/AiStudyCardRecommendationParserService.js');
        $this->generateServicePath = resource_path('js/services/AiStudyCardGenerateCardsService.js');
        $this->dialogPath = resource_path('js/components/Text/AiStudyCardGenerateCardsDialog.vue');
        $this->sideBoxPath = resource_path('js/components/Text/VocabularySideBox.vue');
        $this->boxPath = resource_path('js/components/Text/VocabularyBox.vue');
        $this->bottomSheetPath = resource_path('js/components/Text/VocabularyBottomSheet.vue');
        $this->chineseTextIntegrityTestPath = base_path('tests/Feature/VocabularySideBoxChineseTextIntegrityTest.php');
    }

    /**
     * 1. AiStudyCardDesktopWorkflow.vue must exist as the container/coordinator.
     */
    public function test_workflow_component_file_exists(): void
    {
        $this->assertFileExists($this->workflowPath, 'AiStudyCardDesktopWorkflow.vue must exist as the container.');
    }

    /**
     * 2. AiStudyCardPendingListDialog.vue must exist.
     */
    public function test_pending_list_dialog_file_exists(): void
    {
        $this->assertFileExists($this->pendingListDialogPath, 'AiStudyCardPendingListDialog.vue must exist.');
    }

    /**
     * 3. AiStudyCardPreviewDialog.vue must exist.
     */
    public function test_preview_dialog_file_exists(): void
    {
        $this->assertFileExists($this->previewDialogPath, 'AiStudyCardPreviewDialog.vue must exist.');
    }

    /**
     * 4. AiStudyCardRecommendationPanel.vue must exist.
     */
    public function test_recommendation_panel_file_exists(): void
    {
        $this->assertFileExists($this->recommendationPanelPath, 'AiStudyCardRecommendationPanel.vue must exist.');
    }

    /**
     * 5. AiStudyCardPackagePanel.vue must exist.
     */
    public function test_package_panel_file_exists(): void
    {
        $this->assertFileExists($this->packagePanelPath, 'AiStudyCardPackagePanel.vue must exist.');
    }

    /**
     * 6. AiStudyCardClipboardService.js must exist.
     */
    public function test_clipboard_service_file_exists(): void
    {
        $this->assertFileExists($this->clipboardServicePath, 'AiStudyCardClipboardService.js must exist.');
    }

    /**
     * 7. AiStudyCardDesktopWorkflow must reference (import + register + render)
     *    the new sub-components.
     */
    public function test_workflow_references_sub_components(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString("import AiStudyCardPendingListDialog from './AiStudyCardPendingListDialog.vue'", $contents, 'Workflow must import AiStudyCardPendingListDialog.');
        $this->assertStringContainsString("import AiStudyCardPreviewDialog from './AiStudyCardPreviewDialog.vue'", $contents, 'Workflow must import AiStudyCardPreviewDialog.');
        $this->assertStringContainsString('AiStudyCardPendingListDialog,', $contents, 'Workflow must register AiStudyCardPendingListDialog in components.');
        $this->assertStringContainsString('AiStudyCardPreviewDialog,', $contents, 'Workflow must register AiStudyCardPreviewDialog in components.');
        $this->assertStringContainsString('<AiStudyCardPendingListDialog', $contents, 'Workflow must render <AiStudyCardPendingListDialog>.');
        $this->assertStringContainsString('<AiStudyCardPreviewDialog', $contents, 'Workflow must render <AiStudyCardPreviewDialog>.');
        // Clipboard service must be imported by the workflow.
        $this->assertStringContainsString("import { copyTextToClipboard } from '../../services/AiStudyCardClipboardService.js'", $contents, 'Workflow must import copyTextToClipboard from AiStudyCardClipboardService.');
    }

    /**
     * 8. Workflow must NOT contain the pending list template fingerprints.
     *    These templates now live in AiStudyCardPendingListDialog.vue.
     */
    public function test_workflow_does_not_contain_pending_list_template_fingerprints(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $forbiddenFingerprints = [
            '待解释 ({{ aiPendingItems.length }})',
            '已取消 ({{ aiPendingDismissedItems.length }})',
            'v-list-item v-for="item in aiPendingItems"',
        ];
        foreach ($forbiddenFingerprints as $fp) {
            $this->assertStringNotContainsString($fp, $contents, "Workflow must NOT contain pending list fingerprint: $fp (lives in AiStudyCardPendingListDialog).");
        }
    }

    /**
     * 9. Workflow must NOT contain the AI recommendation panel fingerprints.
     *    These templates now live in AiStudyCardRecommendationPanel.vue.
     */
    public function test_workflow_does_not_contain_recommendation_panel_fingerprints(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $forbiddenFingerprints = [
            '粘贴 AI 返回的推荐词 JSON',
            'recommended_items',
            '全选推荐词',
            '全不选推荐词',
        ];
        foreach ($forbiddenFingerprints as $fp) {
            $this->assertStringNotContainsString($fp, $contents, "Workflow must NOT contain recommendation panel fingerprint: $fp (lives in AiStudyCardRecommendationPanel).");
        }
    }

    /**
     * 10. Workflow must NOT contain the package panel fingerprints.
     *     These templates now live in AiStudyCardPackagePanel.vue.
     */
    public function test_workflow_does_not_contain_package_panel_fingerprints(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $forbiddenFingerprints = [
            '安全生成包',
            '最终候选包',
            'JSON.stringify(aiPreviewPackage',
            'JSON.stringify(aiFinalCandidatesPackage',
        ];
        foreach ($forbiddenFingerprints as $fp) {
            $this->assertStringNotContainsString($fp, $contents, "Workflow must NOT contain package panel fingerprint: $fp (lives in AiStudyCardPackagePanel).");
        }
    }

    /**
     * 11. Workflow must NOT contain clipboard DOM fallback logic.
     *     The DOM fallback now lives in AiStudyCardClipboardService.js.
     */
    public function test_workflow_does_not_contain_clipboard_dom_fallback(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $this->assertStringNotContainsString("document.createElement('textarea')", $contents, 'Workflow must NOT contain document.createElement("textarea") — clipboard fallback lives in AiStudyCardClipboardService.');
        $this->assertStringNotContainsString('document.execCommand(\'copy\')', $contents, 'Workflow must NOT contain document.execCommand("copy") — clipboard fallback lives in AiStudyCardClipboardService.');
    }

    /**
     * 12. AiStudyCardClipboardService must contain the clipboard fallback.
     */
    public function test_clipboard_service_contains_fallback(): void
    {
        $contents = file_get_contents($this->clipboardServicePath);
        $this->assertStringContainsString('export function copyTextToClipboard', $contents, 'Clipboard service must export copyTextToClipboard.');
        $this->assertStringContainsString('navigator.clipboard', $contents, 'Clipboard service must use navigator.clipboard API.');
        $this->assertStringContainsString("document.createElement('textarea')", $contents, 'Clipboard service must contain textarea fallback.');
        $this->assertStringContainsString('document.execCommand(\'copy\')', $contents, 'Clipboard service must contain execCommand fallback.');
    }

    /**
     * Helper: assert a component file does NOT import or invoke axios.
     * Checks import statements and actual call patterns (axios.get/post/put/delete/patch),
     * NOT JSDoc prose that happens to contain the word "axios".
     */
    private function assertComponentDoesNotUseAxios(string $path, string $label): void
    {
        $contents = file_get_contents($path);
        $this->assertStringNotContainsString('import axios', $contents, "$label must NOT import axios.");
        $this->assertStringNotContainsString('from \'axios\'', $contents, "$label must NOT import from axios.");
        $this->assertStringNotContainsString('axios.get(', $contents, "$label must NOT call axios.get.");
        $this->assertStringNotContainsString('axios.post(', $contents, "$label must NOT call axios.post.");
        $this->assertStringNotContainsString('axios.put(', $contents, "$label must NOT call axios.put.");
        $this->assertStringNotContainsString('axios.delete(', $contents, "$label must NOT call axios.delete.");
        $this->assertStringNotContainsString('axios.patch(', $contents, "$label must NOT call axios.patch.");
    }

    /**
     * 13. AiStudyCardPendingListDialog must NOT call axios.
     */
    public function test_pending_list_dialog_does_not_call_axios(): void
    {
        $this->assertComponentDoesNotUseAxios($this->pendingListDialogPath, 'AiStudyCardPendingListDialog');
    }

    /**
     * 14. AiStudyCardPreviewDialog must NOT call axios.
     */
    public function test_preview_dialog_does_not_call_axios(): void
    {
        $this->assertComponentDoesNotUseAxios($this->previewDialogPath, 'AiStudyCardPreviewDialog');
    }

    /**
     * 15. AiStudyCardRecommendationPanel must NOT call axios.
     */
    public function test_recommendation_panel_does_not_call_axios(): void
    {
        $this->assertComponentDoesNotUseAxios($this->recommendationPanelPath, 'AiStudyCardRecommendationPanel');
    }

    /**
     * 16. AiStudyCardPackagePanel must NOT call axios.
     */
    public function test_package_panel_does_not_call_axios(): void
    {
        $this->assertComponentDoesNotUseAxios($this->packagePanelPath, 'AiStudyCardPackagePanel');
    }

    /**
     * 17. Sub-components must NOT import Vuex / mapState.
     *     Only the container (AiStudyCardDesktopWorkflow) may use Vuex.
     *     Checks import statements and mapState invocations, not JSDoc prose.
     */
    public function test_sub_components_do_not_import_vuex(): void
    {
        $paths = [
            $this->pendingListDialogPath => 'AiStudyCardPendingListDialog',
            $this->previewDialogPath => 'AiStudyCardPreviewDialog',
            $this->recommendationPanelPath => 'AiStudyCardRecommendationPanel',
            $this->packagePanelPath => 'AiStudyCardPackagePanel',
        ];
        foreach ($paths as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString("from 'vuex'", $contents, "$label must NOT import from vuex.");
            $this->assertStringNotContainsString('from "vuex"', $contents, "$label must NOT import from vuex.");
            $this->assertStringNotContainsString('mapState(', $contents, "$label must NOT invoke mapState().");
        }
    }

    /**
     * 18. Parser service must still guarantee AI recommendations default to UNSELECTED.
     */
    public function test_parser_service_guarantees_unselected_default(): void
    {
        $contents = file_get_contents($this->parserServicePath);
        $this->assertStringContainsString('All recommendations default to UNSELECTED', $contents, 'Parser service must document that recommendations default to unselected.');
    }

    /**
     * 19. Generate-cards service must still initialize sense_zh as empty.
     */
    public function test_generate_service_initializes_sense_zh_empty(): void
    {
        $contents = file_get_contents($this->generateServicePath);
        $this->assertStringContainsString("sense_zh: '', // user must input", $contents, 'Generate-cards service must initialize sense_zh as empty.');
    }

    /**
     * 20. Generate-cards dialog must still show sense_zh required + sense_en optional.
     */
    public function test_dialog_shows_sense_zh_required_and_sense_en_optional(): void
    {
        $contents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('中文释义（必填）', $contents, 'Dialog must mark sense_zh as required.');
        $this->assertStringContainsString('英文解释（可选，可留空）', $contents, 'Dialog must mark sense_en as optional.');
    }

    /**
     * 21. VocabularySideBox must still mount AiStudyCardDesktopWorkflow.
     */
    public function test_side_box_mounts_workflow(): void
    {
        $contents = file_get_contents($this->sideBoxPath);
        $this->assertStringContainsString("import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'", $contents, 'VocabularySideBox must import AiStudyCardDesktopWorkflow.');
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $contents, 'VocabularySideBox must render <AiStudyCardDesktopWorkflow>.');
    }

    /**
     * 22. VocabularyBox must still mount AiStudyCardDesktopWorkflow.
     */
    public function test_vocabulary_box_mounts_workflow(): void
    {
        $contents = file_get_contents($this->boxPath);
        $this->assertStringContainsString("import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'", $contents, 'VocabularyBox must import AiStudyCardDesktopWorkflow.');
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $contents, 'VocabularyBox must render <AiStudyCardDesktopWorkflow>.');
    }

    /**
     * 23. VocabularySideBoxChineseTextIntegrityTest must still exist (regression guard).
     */
    public function test_chinese_text_integrity_test_exists(): void
    {
        $this->assertFileExists($this->chineseTextIntegrityTestPath, 'VocabularySideBoxChineseTextIntegrityTest.php must exist to prevent mojibake regression.');
    }

    /**
     * 24. No external AI provider strings anywhere in the deep-module surface.
     */
    public function test_deep_module_surface_has_no_external_ai_provider_strings(): void
    {
        $forbiddenPatterns = [
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.x.ai',
            'https://api.',
            'http://api.',
        ];

        $paths = [
            $this->workflowPath,
            $this->pendingListDialogPath,
            $this->previewDialogPath,
            $this->recommendationPanelPath,
            $this->packagePanelPath,
            $this->clipboardServicePath,
            $this->parserServicePath,
            $this->generateServicePath,
            $this->dialogPath,
        ];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, basename($path) . " must not call external AI provider: $pattern");
            }
        }
    }

    /**
     * 25. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls
     *     anywhere in the deep-module surface.
     */
    public function test_deep_module_surface_has_no_review_log_fsrs_rating_or_legacy_word_card_calls(): void
    {
        $forbiddenPatterns = [
            '/review-log',
            '/reviews/rate',
            '/reviews/senses/',
            '/fsrs',
            'target_type: \'word\'',
            'target_type: "word"',
            "target_type' => 'word'",
        ];

        $paths = [
            $this->workflowPath,
            $this->pendingListDialogPath,
            $this->previewDialogPath,
            $this->recommendationPanelPath,
            $this->packagePanelPath,
            $this->clipboardServicePath,
            $this->parserServicePath,
            $this->generateServicePath,
            $this->dialogPath,
        ];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, basename($path) . " must not reference forbidden pattern: $pattern");
            }
        }
    }

    /**
     * 26. VocabularyBottomSheet must NOT contain AIStudyCard V5 workflow
     *     (mobile / BottomSheet is explicitly out of scope for this round).
     */
    public function test_vocabulary_bottom_sheet_does_not_contain_v5_workflow(): void
    {
        if (!file_exists($this->bottomSheetPath)) {
            $this->markTestSkipped('VocabularyBottomSheet.vue does not exist — nothing to guard.');
        }

        $contents = file_get_contents($this->bottomSheetPath);
        $forbiddenMarkers = [
            'AiStudyCardDesktopWorkflow',
            'AiStudyCardPendingListDialog',
            'AiStudyCardPreviewDialog',
            'AiStudyCardRecommendationPanel',
            'AiStudyCardPackagePanel',
            'AiStudyCardClipboardService',
            'openGenerateCardsDialog',
            'confirmGenerateCards',
            'aiGenerateCardsDialog',
            'aiGenerateCardsResult',
            '/ai-study-card/generate-cards',
            '/ai-study-card/pending-items',
            '生成学习卡',
            '待 AI 解释',
        ];
        foreach ($forbiddenMarkers as $marker) {
            $this->assertStringNotContainsString($marker, $contents, "VocabularyBottomSheet must NOT contain deep-module marker: $marker (mobile is out of scope).");
        }
    }

    /**
     * 27. Line-count guard: AiStudyCardDesktopWorkflow.vue must not exceed 450 lines.
     *     The container should stay thin. If it grows beyond 450 lines, the
     *     excess must be justified in the final report and marked P2.
     */
    public function test_workflow_line_count_under_450(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $lineCount = substr_count($contents, "\n") + 1;
        $this->assertLessThanOrEqual(450, $lineCount, "AiStudyCardDesktopWorkflow.vue must not exceed 450 lines (current: $lineCount). If exceeded, justify in final report and mark P2.");
    }
}
