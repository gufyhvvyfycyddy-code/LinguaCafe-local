<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2
 *
 * Architecture guard tests for the desktop AIStudyCard V1-V5 feature island
 * convergence.
 *
 * Previously VocabularySideBox.vue and VocabularyBox.vue each carried a full
 * copy of the V1-V5 workflow: pending list dialog, pending / dismissed items,
 * preview selected ids, preview package, AI recommendation JSON input, parsed
 * AI recommendations, selected recommendation indices, final candidates
 * package, final package copy state, generate cards dialog, and result panel.
 * Any change to V1-V5 rules would have to be applied in two places, risking
 * drift between the wide-screen and narrow-screen experiences.
 *
 * This round extracts the entire V1-V5 workflow surface into a single
 * feature island component:
 *   - resources/js/components/Text/AiStudyCardDesktopWorkflow.vue
 * which in turn delegates to two shared services:
 *   - resources/js/services/AiStudyCardRecommendationParserService.js
 *   - resources/js/services/AiStudyCardPendingWorkflowService.js
 * and reuses the previously-extracted V5 shared components:
 *   - resources/js/components/Text/AiStudyCardGenerateCardsDialog.vue
 *   - resources/js/components/Text/AiStudyCardGenerateCardsResult.vue
 *   - resources/js/services/AiStudyCardGenerateCardsService.js
 *
 * Both desktop entry points (VocabularySideBox + VocabularyBox) now render
 * <AiStudyCardDesktopWorkflow /> and no longer carry V1-V5 data, methods, or
 * templates.
 *
 * These guards lock the feature-island boundary so a future edit cannot
 * silently re-introduce V1-V5 workflow state / methods / templates into
 * either parent component.
 *
 * Covers (23 items from the task spec):
 *  1. AiStudyCardDesktopWorkflow.vue exists;
 *  2. VocabularySideBox references AiStudyCardDesktopWorkflow;
 *  3. VocabularyBox references AiStudyCardDesktopWorkflow;
 *  4. VocabularySideBox no longer contains AIStudyCard pending list template;
 *  5. VocabularyBox no longer contains AIStudyCard pending list template;
 *  6. VocabularySideBox no longer defines parseAiRecommendations();
 *  7. VocabularyBox no longer defines parseAiRecommendations();
 *  8. VocabularySideBox no longer defines loadAiPendingItems();
 *  9. VocabularyBox no longer defines loadAiPendingItems();
 * 10. VocabularySideBox no longer calls /ai-study-card/pending-items/preview-package;
 * 11. VocabularyBox no longer calls /ai-study-card/pending-items/preview-package;
 * 12. VocabularySideBox no longer calls /ai-study-card/pending-items/final-candidates-package;
 * 13. VocabularyBox no longer calls /ai-study-card/pending-items/final-candidates-package;
 * 14. AI recommendation parsing logic only lives in the shared parser service;
 * 15. AI recommendations default to UNSELECTED;
 * 16. AI reason not auto-filled into sense_zh;
 * 17. sense_zh initialized as empty;
 * 18. sense_en allowed to be empty;
 * 19. VocabularyBottomSheet does NOT contain AIStudyCard V5 workflow;
 * 20. No external AI provider strings anywhere in the workflow surface;
 * 21. No ReviewLog / FSRS rating / legacy word card creation calls;
 * 22. Shared services expose the documented pure functions;
 * 23. AiStudyCardDesktopWorkflow emits generated event (parent contract).
 */
class AiStudyCardDesktopWorkflowArchitectureGuardTest extends TestCase
{
    private string $sideBoxPath;
    private string $boxPath;
    private string $bottomSheetPath;
    private string $workflowPath;
    private string $parserPath;
    private string $pendingServicePath;
    private string $dialogPath;
    private string $resultPath;
    private string $generateServicePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sideBoxPath = resource_path('js/components/Text/VocabularySideBox.vue');
        $this->boxPath = resource_path('js/components/Text/VocabularyBox.vue');
        $this->bottomSheetPath = resource_path('js/components/Text/VocabularyBottomSheet.vue');
        $this->workflowPath = resource_path('js/components/Text/AiStudyCardDesktopWorkflow.vue');
        $this->parserPath = resource_path('js/services/AiStudyCardRecommendationParserService.js');
        $this->pendingServicePath = resource_path('js/services/AiStudyCardPendingWorkflowService.js');
        $this->dialogPath = resource_path('js/components/Text/AiStudyCardGenerateCardsDialog.vue');
        $this->resultPath = resource_path('js/components/Text/AiStudyCardGenerateCardsResult.vue');
        $this->generateServicePath = resource_path('js/services/AiStudyCardGenerateCardsService.js');
    }

    /**
     * 1. AiStudyCardDesktopWorkflow.vue must exist as the feature island.
     */
    public function test_workflow_component_file_exists(): void
    {
        $this->assertFileExists($this->workflowPath, 'AiStudyCardDesktopWorkflow.vue must exist as the V1-V5 feature island.');
    }

    /**
     * 2. VocabularySideBox must reference AiStudyCardDesktopWorkflow
     *    (import + components registration + template usage).
     */
    public function test_side_box_references_workflow_component(): void
    {
        $contents = file_get_contents($this->sideBoxPath);
        $this->assertStringContainsString("import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'", $contents, 'VocabularySideBox must import AiStudyCardDesktopWorkflow.');
        $this->assertStringContainsString('AiStudyCardDesktopWorkflow,', $contents, 'VocabularySideBox must register AiStudyCardDesktopWorkflow in components.');
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $contents, 'VocabularySideBox must render <AiStudyCardDesktopWorkflow> in template.');
    }

    /**
     * 3. VocabularyBox must reference AiStudyCardDesktopWorkflow
     *    (import + components registration + template usage).
     */
    public function test_vocabulary_box_references_workflow_component(): void
    {
        $contents = file_get_contents($this->boxPath);
        $this->assertStringContainsString("import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'", $contents, 'VocabularyBox must import AiStudyCardDesktopWorkflow.');
        $this->assertStringContainsString('AiStudyCardDesktopWorkflow,', $contents, 'VocabularyBox must register AiStudyCardDesktopWorkflow in components.');
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $contents, 'VocabularyBox must render <AiStudyCardDesktopWorkflow> in template.');
    }

    /**
     * 4 + 5. Neither parent should still contain the AIStudyCard pending list
     *        dialog template. We use the unique v-card-title text "待 AI 解释的词"
     *        as the duplication fingerprint.
     */
    public function test_neither_parent_contains_pending_list_dialog_template(): void
    {
        $fingerprint = '待 AI 解释的词';

        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString($fingerprint, $contents, "$label must NOT contain the AIStudyCard pending list dialog template — use <AiStudyCardDesktopWorkflow> instead.");
            // The old v-dialog with v-model="aiPendingListDialog" inline template should be gone.
            $this->assertStringNotContainsString('<v-dialog v-model="aiPendingListDialog"', $contents, "$label must NOT contain inline <v-dialog v-model=\"aiPendingListDialog\"> — use <AiStudyCardDesktopWorkflow> instead.");
        }
    }

    /**
     * 6 + 7. Neither parent should still define parseAiRecommendations().
     *        The rule now lives in the shared parser service.
     */
    public function test_neither_parent_defines_parse_ai_recommendations(): void
    {
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('parseAiRecommendations(', $contents, "$label must NOT define parseAiRecommendations — it now lives in AiStudyCardRecommendationParserService.");
            $this->assertStringNotContainsString('parseAiRecommendations()', $contents, "$label must NOT define parseAiRecommendations — it now lives in AiStudyCardRecommendationParserService.");
        }
    }

    /**
     * 8 + 9. Neither parent should still define loadAiPendingItems().
     *        The rule now lives in the shared pending workflow service.
     */
    public function test_neither_parent_defines_load_ai_pending_items(): void
    {
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('loadAiPendingItems(', $contents, "$label must NOT define loadAiPendingItems — it now lives in AiStudyCardPendingWorkflowService.");
            $this->assertStringNotContainsString('loadAiPendingItems()', $contents, "$label must NOT define loadAiPendingItems — it now lives in AiStudyCardPendingWorkflowService.");
        }
    }

    /**
     * 10 + 11. Neither parent should directly call the preview-package endpoint.
     *          The call now lives in AiStudyCardPendingWorkflowService.
     */
    public function test_neither_parent_calls_preview_package_endpoint(): void
    {
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('/ai-study-card/pending-items/preview-package', $contents, "$label must NOT call /ai-study-card/pending-items/preview-package directly — use AiStudyCardPendingWorkflowService.");
        }
    }

    /**
     * 12 + 13. Neither parent should directly call the final-candidates-package endpoint.
     *          The call now lives in AiStudyCardPendingWorkflowService.
     */
    public function test_neither_parent_calls_final_candidates_package_endpoint(): void
    {
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('/ai-study-card/pending-items/final-candidates-package', $contents, "$label must NOT call /ai-study-card/pending-items/final-candidates-package directly — use AiStudyCardPendingWorkflowService.");
        }
    }

    /**
     * 14. AI recommendation parsing logic must only live in
     *     AiStudyCardRecommendationParserService.js. The workflow component may
     *     delegate to it, but neither parent nor any other service may
     *     re-implement the parse / dedupe rules.
     */
    public function test_parser_logic_only_lives_in_shared_service(): void
    {
        // The shared parser service must exist and export the documented functions.
        $this->assertFileExists($this->parserPath, 'AiStudyCardRecommendationParserService.js must exist.');
        $parserContents = file_get_contents($this->parserPath);
        $this->assertStringContainsString('export function parseAiRecommendations(', $parserContents, 'Parser service must export parseAiRecommendations.');
        $this->assertStringContainsString('export function rededupeRecommendations(', $parserContents, 'Parser service must export rededupeRecommendations.');
        $this->assertStringContainsString('export function buildUserSelectedKeys(', $parserContents, 'Parser service must export buildUserSelectedKeys.');

        // Neither parent may re-implement the parser.
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('JSON.parse(text)', $contents, "$label must NOT re-implement AI recommendation JSON parsing.");
            $this->assertStringNotContainsString("recommended_items", $contents, "$label must NOT reference recommended_items directly — use AiStudyCardRecommendationParserService.");
        }

        // The workflow component may import the parser but must not re-implement
        // the JSON.parse(text) core logic.
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString('parseAiRecommendations', $workflowContents, 'Workflow component must call parseAiRecommendations from the shared service.');
        $this->assertStringNotContainsString('JSON.parse(text)', $workflowContents, 'Workflow component must NOT re-implement JSON.parse(text) inline — call the shared service.');
    }

    /**
     * 15. AI recommendations must default to UNSELECTED.
     *     The parser service returns recommendations without a `selected` flag,
     *     and the workflow component initializes aiSelectedRecommendationIndices
     *     as an empty array.
     */
    public function test_ai_recommendations_default_to_unselected(): void
    {
        $parserContents = file_get_contents($this->parserPath);
        $this->assertStringContainsString('All recommendations default to UNSELECTED', $parserContents, 'Parser service must document that recommendations default to unselected.');

        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString('aiSelectedRecommendationIndices: []', $workflowContents, 'Workflow component must initialize aiSelectedRecommendationIndices as empty array.');
        $this->assertStringContainsString('AI recommendations default to UNSELECTED', $workflowContents, 'Workflow component must document that AI recommendations default to unselected.');
    }

    /**
     * 16. AI reason must NOT be auto-filled into sense_zh.
     *     The rule lives in the shared generate-cards service (single source of truth).
     */
    public function test_ai_reason_not_auto_filled_into_sense_zh(): void
    {
        $serviceContents = file_get_contents($this->generateServicePath);
        $this->assertStringContainsString("reason: item.reason || '', // reference display only", $serviceContents, 'Service must store reason separately from sense_zh.');
        $this->assertStringNotContainsString('sense_zh: item.reason', $serviceContents, 'Service must NOT auto-fill sense_zh from reason.');

        // Neither parent nor the workflow component may re-introduce reason→sense_zh pre-fill.
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox', $this->workflowPath => 'AiStudyCardDesktopWorkflow'] as $path => $label) {
            $contents = file_get_contents($path);
            $this->assertStringNotContainsString('sense_zh: item.reason', $contents, "$label must NOT auto-fill sense_zh from reason.");
        }
    }

    /**
     * 17. sense_zh must be initialized as empty.
     */
    public function test_sense_zh_initialized_empty(): void
    {
        $serviceContents = file_get_contents($this->generateServicePath);
        $this->assertStringContainsString("sense_zh: '', // user must input", $serviceContents, 'Service must initialize sense_zh as empty (required).');

        $dialogContents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('中文释义（必填）', $dialogContents, 'Shared dialog must mark sense_zh as required.');
    }

    /**
     * 18. sense_en must be allowed to be empty (optional).
     */
    public function test_sense_en_allowed_empty(): void
    {
        $serviceContents = file_get_contents($this->generateServicePath);
        $this->assertStringContainsString("sense_en: '', // optional, may stay empty", $serviceContents, 'Service must initialize sense_en as empty (optional).');

        $dialogContents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('英文解释（可选，可留空）', $dialogContents, 'Shared dialog must mark sense_en as optional.');
    }

    /**
     * 19. VocabularyBottomSheet must NOT contain AIStudyCard V5 workflow
     *     (mobile / BottomSheet is explicitly out of scope for this round).
     */
    public function test_vocabulary_bottom_sheet_does_not_contain_v5_workflow(): void
    {
        if (!file_exists($this->bottomSheetPath)) {
            $this->markTestSkipped('VocabularyBottomSheet.vue does not exist — nothing to guard.');
        }

        $contents = file_get_contents($this->bottomSheetPath);
        $forbiddenMarkers = [
            'openGenerateCardsDialog',
            'confirmGenerateCards',
            'aiGenerateCardsDialog',
            'aiGenerateCardsItems',
            'aiGenerateCardsResult',
            'AiStudyCardGenerateCardsDialog',
            'AiStudyCardGenerateCardsResult',
            'AiStudyCardDesktopWorkflow',
            '/ai-study-card/generate-cards',
            '/ai-study-card/pending-items',
            '生成学习卡',
            '待 AI 解释',
        ];
        foreach ($forbiddenMarkers as $marker) {
            $this->assertStringNotContainsString($marker, $contents, "VocabularyBottomSheet must NOT contain V5 workflow marker: $marker (mobile is out of scope).");
        }
    }

    /**
     * 20. No external AI provider strings anywhere in the workflow surface.
     */
    public function test_workflow_surface_has_no_external_ai_provider_strings(): void
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
            $this->sideBoxPath,
            $this->boxPath,
            $this->workflowPath,
            $this->parserPath,
            $this->pendingServicePath,
            $this->dialogPath,
            $this->resultPath,
            $this->generateServicePath,
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
     * 21. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls
     *     anywhere in the workflow surface.
     */
    public function test_workflow_surface_has_no_review_log_fsrs_rating_or_legacy_word_card_calls(): void
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
            $this->sideBoxPath,
            $this->boxPath,
            $this->workflowPath,
            $this->parserPath,
            $this->pendingServicePath,
            $this->dialogPath,
            $this->resultPath,
            $this->generateServicePath,
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
     * 22. Shared services must expose the documented pure functions.
     */
    public function test_shared_services_expose_documented_functions(): void
    {
        // Recommendation parser service.
        $parserContents = file_get_contents($this->parserPath);
        $this->assertStringContainsString('export function parseAiRecommendations(', $parserContents);
        $this->assertStringContainsString('export function rededupeRecommendations(', $parserContents);
        $this->assertStringContainsString('export function buildUserSelectedKeys(', $parserContents);

        // Pending workflow service.
        $pendingContents = file_get_contents($this->pendingServicePath);
        $this->assertStringContainsString('export function createPendingItem(', $pendingContents);
        $this->assertStringContainsString('export function listPendingItems(', $pendingContents);
        $this->assertStringContainsString('export function dismissPendingItem(', $pendingContents);
        $this->assertStringContainsString('export function restorePendingItem(', $pendingContents);
        $this->assertStringContainsString('export function buildPreviewPackage(', $pendingContents);
        $this->assertStringContainsString('export function buildFinalCandidatesPackage(', $pendingContents);

        // Generate cards service still exposes V5 helpers.
        $generateContents = file_get_contents($this->generateServicePath);
        $this->assertStringContainsString('export function buildGenerateCardItems(', $generateContents);
        $this->assertStringContainsString('export function filterConfirmedGenerateCardItems(', $generateContents);
        $this->assertStringContainsString('export function generateAiStudyCards(', $generateContents);
    }

    /**
     * 23. AiStudyCardDesktopWorkflow must emit `generated` after V5 success
     *     so parents can react without owning V5 state. Also must NOT call
     *     backend directly inside the dialog/result components.
     */
    public function test_workflow_emits_generated_and_does_not_call_backend_in_shared_components(): void
    {
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString("\$emit('generated'", $workflowContents, 'Workflow component must emit generated event after V5 success.');

        // Shared dialog/result still emit events and do NOT call axios directly.
        $dialogContents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString("\$emit('input'", $dialogContents, 'Dialog must emit input event for v-model.');
        $this->assertStringContainsString("\$emit('confirm')", $dialogContents, 'Dialog must emit confirm event.');
        $this->assertStringNotContainsString('axios.post', $dialogContents, 'Dialog must NOT call axios.post directly.');
        $this->assertStringNotContainsString('axios.get', $dialogContents, 'Dialog must NOT call axios.get directly.');

        $resultContents = file_get_contents($this->resultPath);
        $this->assertStringContainsString("\$emit('go-to-sense-reviews')", $resultContents, 'Result must emit go-to-sense-reviews event.');
        $this->assertStringContainsString("\$emit('dismiss')", $resultContents, 'Result must emit dismiss event.');
        $this->assertStringNotContainsString('axios.post', $resultContents, 'Result must NOT call axios.post directly.');
        $this->assertStringNotContainsString('axios.get', $resultContents, 'Result must NOT call axios.get directly.');
    }

    /**
     * Sanity: the workflow component must own the V1-V5 state. This locks the
     * boundary so a future refactor cannot move state back into parents.
     */
    public function test_workflow_component_owns_v1_v5_state(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $requiredDataFields = [
            'aiStudyCardPendingLoading:',
            'aiPendingListDialog:',
            'aiPendingItems:',
            'aiPendingDismissedItems:',
            'aiPreviewSelectedItemIds:',
            'aiPreviewPackage:',
            'aiRecommendationJsonInput:',
            'aiRecommendations:',
            'aiSelectedRecommendationIndices:',
            'aiFinalCandidatesPackage:',
            'aiGenerateCardsDialog:',
            'aiGenerateCardsItems:',
            'aiGenerateCardsResult:',
        ];
        foreach ($requiredDataFields as $field) {
            $this->assertStringContainsString($field, $contents, "Workflow component must own data field: $field");
        }
    }

    /**
     * Sanity: the workflow component must own the V1-V5 methods.
     */
    public function test_workflow_component_owns_v1_v5_methods(): void
    {
        $contents = file_get_contents($this->workflowPath);
        $requiredMethods = [
            'markAiStudyCardPending(',
            'openAiPendingListDialog(',
            'loadAiPendingItems(',
            'loadAiPendingDismissedItems(',
            'dismissAiPendingItem(',
            'restoreAiPendingItem(',
            'openAiStudyCardPreview(',
            'togglePreviewItemSelection(',
            'selectAllPreviewItems(',
            'deselectAllPreviewItems(',
            'parseAiRecommendations(',
            'rededupeAiRecommendationsAfterUserSelectionChange(',
            'toggleAiRecommendationSelection(',
            'selectAllAiRecommendations(',
            'deselectAllAiRecommendations(',
            'generatePreviewPackage(',
            'copyPreviewPackage(',
            'generateFinalCandidatesPackage(',
            'copyFinalCandidatesPackage(',
            'openGenerateCardsDialog(',
            'confirmGenerateCards(',
            'goToSenseReviews(',
        ];
        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString($method, $contents, "Workflow component must own method: $method");
        }
    }

    /**
     * Sanity: neither parent should own any of the V1-V5 data fields.
     * This is the strongest guard against state drift back into parents.
     */
    public function test_neither_parent_owns_v1_v5_data_fields(): void
    {
        $forbiddenFields = [
            'aiPendingListDialog:',
            'aiPendingItems:',
            'aiPendingDismissedItems:',
            'aiPreviewSelectedItemIds:',
            'aiPreviewPackage:',
            'aiRecommendationJsonInput:',
            'aiRecommendations:',
            'aiSelectedRecommendationIndices:',
            'aiFinalCandidatesPackage:',
            'aiGenerateCardsDialog:',
            'aiGenerateCardsItems:',
            'aiGenerateCardsResult:',
        ];
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            foreach ($forbiddenFields as $field) {
                $this->assertStringNotContainsString($field, $contents, "$label must NOT own V1-V5 data field: $field (now owned by AiStudyCardDesktopWorkflow).");
            }
        }
    }

    /**
     * Sanity: neither parent should own any of the V1-V5 methods.
     */
    public function test_neither_parent_owns_v1_v5_methods(): void
    {
        $forbiddenMethods = [
            'markAiStudyCardPending(',
            'openAiPendingListDialog(',
            'loadAiPendingItems(',
            'loadAiPendingDismissedItems(',
            'dismissAiPendingItem(',
            'restoreAiPendingItem(',
            'openAiStudyCardPreview(',
            'togglePreviewItemSelection(',
            'selectAllPreviewItems(',
            'deselectAllPreviewItems(',
            'parseAiRecommendations(',
            'rededupeAiRecommendationsAfterUserSelectionChange(',
            'toggleAiRecommendationSelection(',
            'selectAllAiRecommendations(',
            'deselectAllAiRecommendations(',
            'generatePreviewPackage(',
            'copyPreviewPackage(',
            'generateFinalCandidatesPackage(',
            'copyFinalCandidatesPackage(',
            'openGenerateCardsDialog(',
            'confirmGenerateCards(',
            'goToSenseReviews(',
        ];
        foreach ([$this->sideBoxPath => 'VocabularySideBox', $this->boxPath => 'VocabularyBox'] as $path => $label) {
            $contents = file_get_contents($path);
            foreach ($forbiddenMethods as $method) {
                $this->assertStringNotContainsString($method, $contents, "$label must NOT own V1-V5 method: $method (now owned by AiStudyCardDesktopWorkflow).");
            }
        }
    }
}
