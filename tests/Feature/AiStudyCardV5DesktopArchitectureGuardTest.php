<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1
 * + GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2
 *
 * Architecture guard tests for the desktop AIStudyCard V5 convergence.
 *
 * Round 1 (GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1)
 * previously VocabularySideBox.vue and VocabularyBox.vue each carried their
 * own copy of the V5 "generate study cards" flow: result template, confirm
 * dialog template, item-building logic, and POST request. That round
 * extracted the shared V5 surface into:
 *   - resources/js/services/AiStudyCardGenerateCardsService.js (pure helpers)
 *   - resources/js/components/Text/AiStudyCardGenerateCardsDialog.vue (shared dialog)
 *   - resources/js/components/Text/AiStudyCardGenerateCardsResult.vue (shared result panel)
 *
 * Round 2 (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2) further
 * converged the entire V1-V5 desktop workflow into a single feature island
 * component AiStudyCardDesktopWorkflow.vue, which is now the only place that
 * imports the shared dialog/result components and the shared V5 service
 * helpers. VocabularySideBox.vue and VocabularyBox.vue no longer import the
 * shared V5 modules directly — they render <AiStudyCardDesktopWorkflow />.
 *
 * These guards lock the convergence so a future edit cannot silently
 * re-introduce duplicate V5 templates / logic into either parent component.
 *
 * Covers:
 *  1. Shared service file exists;
 *  2. Shared dialog component file exists;
 *  3. Shared result component file exists;
 *  4. VocabularySideBox references the workflow feature island (which in turn
 *     references shared dialog + result);
 *  5. VocabularyBox references the workflow feature island (which in turn
 *     references shared dialog + result);
 *  6. AiStudyCardDesktopWorkflow imports the shared service helpers;
 *  7. Neither parent still contains the large duplicated V5 confirm dialog template;
 *  8. Neither parent still contains the large duplicated V5 result template;
 *  9. Neither parent duplicates buildGenerateCardItems item-construction logic;
 * 10. AI reason still not auto-filled into sense_zh (rule lives in service);
 * 11. sense_zh still initialized empty (rule lives in service);
 * 12. sense_en still optional (rule lives in service + dialog);
 * 13. VocabularyBottomSheet does NOT contain AIStudyCard V5 flow (out of scope);
 * 14. No external AI provider strings anywhere in the V5 shared surface;
 * 15. No ReviewLog / FSRS rating / legacy word card creation calls.
 */
class AiStudyCardV5DesktopArchitectureGuardTest extends TestCase
{
    private string $sideBoxPath;
    private string $boxPath;
    private string $bottomSheetPath;
    private string $workflowPath;
    private string $dialogPath;
    private string $resultPath;
    private string $servicePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sideBoxPath = resource_path('js/components/Text/VocabularySideBox.vue');
        $this->boxPath = resource_path('js/components/Text/VocabularyBox.vue');
        $this->bottomSheetPath = resource_path('js/components/Text/VocabularyBottomSheet.vue');
        $this->workflowPath = resource_path('js/components/Text/AiStudyCardDesktopWorkflow.vue');
        $this->dialogPath = resource_path('js/components/Text/AiStudyCardGenerateCardsDialog.vue');
        $this->resultPath = resource_path('js/components/Text/AiStudyCardGenerateCardsResult.vue');
        $this->servicePath = resource_path('js/services/AiStudyCardGenerateCardsService.js');
    }

    /**
     * 1. Shared service file must exist.
     */
    public function test_shared_service_file_exists(): void
    {
        $this->assertFileExists($this->servicePath, 'AiStudyCardGenerateCardsService.js must exist as the shared V5 helper.');
    }

    /**
     * 2. Shared dialog component file must exist.
     */
    public function test_shared_dialog_component_file_exists(): void
    {
        $this->assertFileExists($this->dialogPath, 'AiStudyCardGenerateCardsDialog.vue must exist as the shared V5 dialog.');
    }

    /**
     * 3. Shared result component file must exist.
     */
    public function test_shared_result_component_file_exists(): void
    {
        $this->assertFileExists($this->resultPath, 'AiStudyCardGenerateCardsResult.vue must exist as the shared V5 result panel.');
    }

    /**
     * 4. VocabularySideBox must reference the workflow feature island, which
     *    in turn references the shared dialog (directly) + result (via PreviewDialog).
     *
     *    After Round 2 (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2),
     *    VocabularySideBox.vue no longer imports AiStudyCardGenerateCardsDialog
     *    or AiStudyCardGenerateCardsResult directly. It renders
     *    <AiStudyCardDesktopWorkflow />, which is the only place that imports
     *    those shared V5 components.
     *
     *    After Round 4 (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4),
     *    the workflow still directly imports + renders AiStudyCardGenerateCardsDialog
     *    (V5 confirm dialog). AiStudyCardGenerateCardsResult is now imported +
     *    rendered by AiStudyCardPreviewDialog (a presentational sub-component of
     *    the workflow). The contract is locked at the workflow-surface boundary:
     *    the Result component must still be reachable from the workflow surface.
     */
    public function test_side_box_references_shared_components(): void
    {
        $contents = file_get_contents($this->sideBoxPath);
        $this->assertStringContainsString("import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'", $contents, 'VocabularySideBox must import AiStudyCardDesktopWorkflow (the feature island).');
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $contents, 'VocabularySideBox must render <AiStudyCardDesktopWorkflow> in template.');
        $this->assertStringContainsString('AiStudyCardDesktopWorkflow,', $contents, 'VocabularySideBox must register AiStudyCardDesktopWorkflow in components.');

        // The shared dialog is still imported + rendered directly by the workflow.
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString("import AiStudyCardGenerateCardsDialog from './AiStudyCardGenerateCardsDialog.vue'", $workflowContents, 'AiStudyCardDesktopWorkflow must import AiStudyCardGenerateCardsDialog.');
        $this->assertStringContainsString('<AiStudyCardGenerateCardsDialog', $workflowContents, 'AiStudyCardDesktopWorkflow must render <AiStudyCardGenerateCardsDialog>.');

        // The shared result is now imported + rendered by AiStudyCardPreviewDialog
        // (a presentational sub-component of the workflow surface).
        $previewDialogPath = resource_path('js/components/Text/AiStudyCardPreviewDialog.vue');
        $this->assertFileExists($previewDialogPath, 'AiStudyCardPreviewDialog.vue must exist as the V3-V5 preview sub-component.');
        $previewContents = file_get_contents($previewDialogPath);
        $this->assertStringContainsString("import AiStudyCardGenerateCardsResult from './AiStudyCardGenerateCardsResult.vue'", $previewContents, 'AiStudyCardPreviewDialog must import AiStudyCardGenerateCardsResult.');
        $this->assertStringContainsString('<AiStudyCardGenerateCardsResult', $previewContents, 'AiStudyCardPreviewDialog must render <AiStudyCardGenerateCardsResult>.');

        // The workflow must render <AiStudyCardPreviewDialog> and pass the result payload to it.
        $this->assertStringContainsString('<AiStudyCardPreviewDialog', $workflowContents, 'AiStudyCardDesktopWorkflow must render <AiStudyCardPreviewDialog>.');
        $this->assertStringContainsString(':ai-generate-cards-result="aiGenerateCardsResult"', $workflowContents, 'AiStudyCardDesktopWorkflow must pass aiGenerateCardsResult to AiStudyCardPreviewDialog.');
    }

    /**
     * 5. VocabularyBox must reference the workflow feature island, which in
     *    turn references the shared dialog (directly) + result (via PreviewDialog).
     */
    public function test_vocabulary_box_references_shared_components(): void
    {
        $contents = file_get_contents($this->boxPath);
        $this->assertStringContainsString("import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'", $contents, 'VocabularyBox must import AiStudyCardDesktopWorkflow (the feature island).');
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $contents, 'VocabularyBox must render <AiStudyCardDesktopWorkflow> in template.');
        $this->assertStringContainsString('AiStudyCardDesktopWorkflow,', $contents, 'VocabularyBox must register AiStudyCardDesktopWorkflow in components.');

        // The shared dialog registration lives in the workflow component.
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString('AiStudyCardGenerateCardsDialog,', $workflowContents, 'AiStudyCardDesktopWorkflow must register AiStudyCardGenerateCardsDialog in components.');

        // The shared result registration lives in AiStudyCardPreviewDialog (workflow sub-component).
        $previewDialogPath = resource_path('js/components/Text/AiStudyCardPreviewDialog.vue');
        $previewContents = file_get_contents($previewDialogPath);
        $this->assertStringContainsString('AiStudyCardGenerateCardsResult,', $previewContents, 'AiStudyCardPreviewDialog must register AiStudyCardGenerateCardsResult in components.');
    }

    /**
     * 6. AiStudyCardDesktopWorkflow (the feature island) must import the
     *    shared service helpers. Parents no longer import these directly.
     */
    public function test_both_parents_import_shared_service_helpers(): void
    {
        // The workflow component is now the only importer of the V5 helpers.
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString("from '../../services/AiStudyCardGenerateCardsService", $workflowContents, 'AiStudyCardDesktopWorkflow must import from AiStudyCardGenerateCardsService.');
        $this->assertStringContainsString('buildGenerateCardItems', $workflowContents, 'AiStudyCardDesktopWorkflow must import buildGenerateCardItems.');
        $this->assertStringContainsString('filterConfirmedGenerateCardItems', $workflowContents, 'AiStudyCardDesktopWorkflow must import filterConfirmedGenerateCardItems.');
        $this->assertStringContainsString('generateAiStudyCards', $workflowContents, 'AiStudyCardDesktopWorkflow must import generateAiStudyCards.');

        // Parents must NOT import the shared service helpers directly anymore.
        // Match both './' and '../../' prefix variants to be robust.
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('services/AiStudyCardGenerateCardsService', $contents, "$label must NOT import AiStudyCardGenerateCardsService directly — use <AiStudyCardDesktopWorkflow> instead.");
        }
    }

    /**
     * 7. Neither parent should still contain the large duplicated V5 confirm
     *    dialog template. The dialog now lives in AiStudyCardGenerateCardsDialog.vue.
     *    We use the unique dialog title text + per-item v-text-field block as the
     *    duplication fingerprint.
     */
    public function test_neither_parent_contains_duplicated_v5_confirm_dialog_template(): void
    {
        // Unique fingerprint of the duplicated confirm dialog template.
        // After convergence, this block must only appear in AiStudyCardGenerateCardsDialog.vue.
        $fingerprint = '推荐理由（参考说明，不是释义）：{{ item.reason }}';

        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString($fingerprint, $contents, "$label must NOT contain the duplicated V5 confirm dialog template — use <AiStudyCardGenerateCardsDialog> instead.");
            // The old v-dialog with v-model="aiGenerateCardsDialog" inline template should be gone.
            $this->assertStringNotContainsString('<v-dialog v-model="aiGenerateCardsDialog"', $contents, "$label must NOT contain inline <v-dialog v-model=\"aiGenerateCardsDialog\"> — use <AiStudyCardGenerateCardsDialog v-model=\"aiGenerateCardsDialog\"> instead.");
        }
    }

    /**
     * 8. Neither parent should still contain the large duplicated V5 result
     *    template. The result panel now lives in AiStudyCardGenerateCardsResult.vue.
     */
    public function test_neither_parent_contains_duplicated_v5_result_template(): void
    {
        // Unique fingerprint of the duplicated result template.
        $fingerprint = '新建学习卡：';

        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString($fingerprint, $contents, "$label must NOT contain the duplicated V5 result template — use <AiStudyCardGenerateCardsResult> instead.");
            // The old inline result block with v-if="aiGenerateCardsResult" + class="生成学习卡结果"
            // wrapper should be gone (now lives in the shared component).
            $this->assertStringNotContainsString('生成学习卡结果', $contents, "$label must NOT contain inline '生成学习卡结果' header — use <AiStudyCardGenerateCardsResult> instead.");
        }
    }

    /**
     * 9. Neither parent duplicates the buildGenerateCardItems item-construction logic.
     *    After convergence, the per-item push({...}) blocks with source: 'user_selected' /
     *    'ai_recommended' must only appear in the shared service.
     */
    public function test_neither_parent_duplicates_build_generate_card_items_logic(): void
    {
        // Fingerprint of the duplicated item-construction logic.
        $fingerprint = "source: 'user_selected',";

        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString($fingerprint, $contents, "$label must NOT duplicate buildGenerateCardItems item-construction — call buildGenerateCardItems() from the shared service instead.");
        }

        // The shared service MUST contain the item-construction logic (single source of truth).
        $serviceContents = file_get_contents($this->servicePath);
        $this->assertStringContainsString("source: 'user_selected',", $serviceContents, 'AiStudyCardGenerateCardsService must contain user_selected item construction.');
        $this->assertStringContainsString("source: 'ai_recommended',", $serviceContents, 'AiStudyCardGenerateCardsService must contain ai_recommended item construction.');
    }

    /**
     * 10. AI reason must NOT be auto-filled into sense_zh. Rule lives in the shared service.
     */
    public function test_ai_reason_not_auto_filled_into_sense_zh_in_service(): void
    {
        $serviceContents = file_get_contents($this->servicePath);
        $this->assertStringContainsString("reason: item.reason || '', // reference display only", $serviceContents, 'Service must store reason separately from sense_zh.');
        $this->assertStringContainsString("sense_zh: '', // user must input", $serviceContents, 'Service must initialize sense_zh as empty.');
        $this->assertStringNotContainsString('sense_zh: item.reason', $serviceContents, 'Service must NOT auto-fill sense_zh from reason.');

        // Neither parent should re-introduce reason→sense_zh pre-fill.
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('sense_zh: item.reason', $contents, "$label must NOT auto-fill sense_zh from reason.");
        }
    }

    /**
     * 11. sense_zh still initialized empty (rule lives in service).
     * 12. sense_en still optional (rule lives in service + dialog).
     */
    public function test_service_initializes_sense_zh_empty_and_sense_en_optional(): void
    {
        $serviceContents = file_get_contents($this->servicePath);
        $this->assertStringContainsString("sense_zh: '', // user must input", $serviceContents, 'Service must initialize sense_zh as empty (required).');
        $this->assertStringContainsString("sense_en: '', // optional, may stay empty", $serviceContents, 'Service must initialize sense_en as empty (optional).');

        $dialogContents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('中文释义（必填）', $dialogContents, 'Shared dialog must mark sense_zh as required.');
        $this->assertStringContainsString('英文解释（可选，可留空）', $dialogContents, 'Shared dialog must mark sense_en as optional.');
    }

    /**
     * 13. VocabularyBottomSheet must NOT contain AIStudyCard V5 flow.
     *     Mobile / BottomSheet is explicitly out of scope for this round.
     */
    public function test_vocabulary_bottom_sheet_does_not_contain_v5_flow(): void
    {
        if (!file_exists($this->bottomSheetPath)) {
            $this->markTestSkipped('VocabularyBottomSheet.vue does not exist — nothing to guard.');
        }

        $contents = file_get_contents($this->bottomSheetPath);
        $forbiddenV5Markers = [
            'openGenerateCardsDialog',
            'confirmGenerateCards',
            'aiGenerateCardsDialog',
            'aiGenerateCardsItems',
            'aiGenerateCardsResult',
            'AiStudyCardGenerateCardsDialog',
            'AiStudyCardGenerateCardsResult',
            '/ai-study-card/generate-cards',
            '生成学习卡',
        ];
        foreach ($forbiddenV5Markers as $marker) {
            $this->assertStringNotContainsString($marker, $contents, "VocabularyBottomSheet must NOT contain V5 marker: $marker (mobile is out of scope).");
        }
    }

    /**
     * 14. No external AI provider strings anywhere in the V5 shared surface.
     */
    public function test_v5_shared_surface_has_no_external_ai_provider_strings(): void
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

        $paths = [$this->sideBoxPath, $this->boxPath, $this->workflowPath, $this->dialogPath, $this->resultPath, $this->servicePath];
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
     * 15. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls
     *     anywhere in the V5 shared surface.
     */
    public function test_v5_shared_surface_has_no_review_log_fsrs_rating_or_legacy_word_card_calls(): void
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

        $paths = [$this->sideBoxPath, $this->boxPath, $this->workflowPath, $this->dialogPath, $this->resultPath, $this->servicePath];
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
     * Shared service must expose the three documented pure functions.
     */
    public function test_service_exposes_three_pure_functions(): void
    {
        $contents = file_get_contents($this->servicePath);
        $this->assertStringContainsString('export function buildGenerateCardItems(', $contents, 'Service must export buildGenerateCardItems.');
        $this->assertStringContainsString('export function filterConfirmedGenerateCardItems(', $contents, 'Service must export filterConfirmedGenerateCardItems.');
        $this->assertStringContainsString('export function generateAiStudyCards(', $contents, 'Service must export generateAiStudyCards.');
    }

    /**
     * Shared dialog must emit confirm + input events (no direct backend calls).
     */
    public function test_shared_dialog_emits_events_and_does_not_call_backend(): void
    {
        $contents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString("\$emit('input'", $contents, 'Dialog must emit input event for v-model.');
        $this->assertStringContainsString("\$emit('confirm')", $contents, 'Dialog must emit confirm event.');
        $this->assertStringNotContainsString('axios.post', $contents, 'Dialog must NOT call axios.post directly.');
        $this->assertStringNotContainsString('axios.get', $contents, 'Dialog must NOT call axios.get directly.');
    }

    /**
     * Shared result must emit go-to-sense-reviews + dismiss events (no direct backend calls).
     */
    public function test_shared_result_emits_events_and_does_not_call_backend(): void
    {
        $contents = file_get_contents($this->resultPath);
        $this->assertStringContainsString("\$emit('go-to-sense-reviews')", $contents, 'Result must emit go-to-sense-reviews event.');
        $this->assertStringContainsString("\$emit('dismiss')", $contents, 'Result must emit dismiss event.');
        $this->assertStringNotContainsString('axios.post', $contents, 'Result must NOT call axios.post directly.');
        $this->assertStringNotContainsString('axios.get', $contents, 'Result must NOT call axios.get directly.');
    }
}
