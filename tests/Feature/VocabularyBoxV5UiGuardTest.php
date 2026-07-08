<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GM52-AIStudyCardV5-VocabularyBoxNarrowScreenParity-1000-1
 * + GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1
 * + GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2
 *
 * Frontend / UI guard tests for the V5 "generate study cards" flow inside the
 * narrow-screen fallback VocabularyBox.vue.
 *
 * The project currently has no dedicated Vue component test runner, so these
 * are PHP source-string guards that scan the component source to lock in the
 * UI contract — mirroring the approach in InlineSensePreviewUiGuardTest and
 * LegacyEntryUiGuardTest. When the project adds a real Vue testing harness
 * (e.g. @vue/test-utils), these guards should be upgraded to true component
 * render assertions.
 *
 * Round 1 (GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1):
 *   The V5 dialog template and result template moved into shared components
 *   (AiStudyCardGenerateCardsDialog.vue / AiStudyCardGenerateCardsResult.vue)
 *   and the V5 item-building / request logic moved into a shared service
 *   (AiStudyCardGenerateCardsService.js). VocabularyBox.vue still owned the
 *   V5 dialog open state, items / loading / error / result data fields, and
 *   the openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews
 *   methods (delegating to the shared service).
 *
 * Round 2 (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2):
 *   The entire V1-V5 desktop workflow (including the V5 dialog/result state
 *   and methods previously held by VocabularyBox.vue) was extracted into a
 *   single feature island component: AiStudyCardDesktopWorkflow.vue.
 *   VocabularyBox.vue now ONLY renders <AiStudyCardDesktopWorkflow />; it no
 *   longer imports the shared V5 dialog / result / service modules directly,
 *   and no longer carries the V5 data fields or methods.
 *
 * UI-contract assertions that previously scanned VocabularyBox.vue for V5
 * state / methods / template now scan AiStudyCardDesktopWorkflow.vue (the
 * new single source of truth), while VocabularyBox.vue is checked only for
 * wiring (workflow component import + template usage). This locks the
 * contract at the feature island boundary and prevents drift.
 *
 * Covers (narrow-screen VocabularyBox.vue + AiStudyCardDesktopWorkflow +
 *        shared V5 components):
 *  1. "生成学习卡" entry button exists in the workflow component;
 *  2. VocabularyBox references AiStudyCardDesktopWorkflow (feature island);
 *  3. AiStudyCardDesktopWorkflow references shared dialog + result;
 *  4. AiStudyCardDesktopWorkflow imports the shared service helpers;
 *  5. "中文释义（必填）" copy exists in the shared dialog;
 *  6. "英文解释（可选，可留空）" copy exists in the shared dialog;
 *  7. AI reason displayed as "推荐理由（参考说明，不是释义）" in the shared dialog;
 *  8. AI reason is NOT auto-filled into sense_zh (service initializes empty);
 *  9. Result section shows source binding status in the shared result component;
 * 10. "进入 /reviews/senses 复习" entry exists in the shared result component;
 * 11. No external AI provider calls anywhere in the V5 shared surface;
 * 12. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls;
 * 13. openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews methods
 *     exist in the workflow component (not in VocabularyBox);
 * 14. V5 data fields exist in the workflow component (not in VocabularyBox);
 * 15. V5 safety copy "这不是 AI 自动调用" exists in shared components;
 * 16. Result section shows created / skipped / duplicate / failed counts.
 * 17. V5 dialog shows explicit "将生成 / 将跳过" counts before confirm;
 * 18. V5 dialog disables confirm button and guides user when 0 definitions filled;
 * 19. V5 dialog shows per-candidate "将生成 / 将跳过" status chip based on sense_zh.
 */
class VocabularyBoxV5UiGuardTest extends TestCase
{
    private string $vocabularyBoxPath;
    private string $workflowPath;
    private string $dialogPath;
    private string $resultPath;
    private string $servicePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vocabularyBoxPath = resource_path('js/components/Text/VocabularyBox.vue');
        $this->workflowPath = resource_path('js/components/Text/AiStudyCardDesktopWorkflow.vue');
        $this->dialogPath = resource_path('js/components/Text/AiStudyCardGenerateCardsDialog.vue');
        $this->resultPath = resource_path('js/components/Text/AiStudyCardGenerateCardsResult.vue');
        $this->servicePath = resource_path('js/services/AiStudyCardGenerateCardsService.js');
    }

    public function test_vocabulary_box_file_exists(): void
    {
        $this->assertFileExists($this->vocabularyBoxPath, 'VocabularyBox.vue must exist for narrow-screen fallback.');
    }

    /**
     * 1. "生成学习卡" entry button must exist so narrow-screen users can enter V5.
     *    After Round 2, the button lives inside AiStudyCardDesktopWorkflow.vue
     *    (the feature island). VocabularyBox.vue must render that workflow
     *    component so the entry button is reachable from the narrow-screen
     *    fallback surface.
     */
    public function test_vocabulary_box_contains_generate_cards_entry_button(): void
    {
        $boxContents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $boxContents, 'VocabularyBox must render <AiStudyCardDesktopWorkflow> so the V5 entry button is reachable.');

        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString('生成学习卡', $workflowContents, 'AiStudyCardDesktopWorkflow must show "生成学习卡" button label.');
    }

    /**
     * 2. VocabularyBox must reference the AiStudyCardDesktopWorkflow feature island
     *    (import + components registration + template usage). The workflow component
     *    in turn references the shared AiStudyCardGenerateCardsDialog.
     */
    public function test_vocabulary_box_references_shared_dialog_component(): void
    {
        $boxContents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString("import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'", $boxContents, 'VocabularyBox must import AiStudyCardDesktopWorkflow.');
        $this->assertStringContainsString('AiStudyCardDesktopWorkflow,', $boxContents, 'VocabularyBox must register AiStudyCardDesktopWorkflow in components.');
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $boxContents, 'VocabularyBox must render <AiStudyCardDesktopWorkflow> in template.');

        // The shared dialog is now imported only by the workflow component.
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString("import AiStudyCardGenerateCardsDialog from './AiStudyCardGenerateCardsDialog.vue'", $workflowContents, 'AiStudyCardDesktopWorkflow must import AiStudyCardGenerateCardsDialog.');
        $this->assertStringContainsString('<AiStudyCardGenerateCardsDialog', $workflowContents, 'AiStudyCardDesktopWorkflow must render <AiStudyCardGenerateCardsDialog> in template.');
        $this->assertStringContainsString('v-model="aiGenerateCardsDialog"', $workflowContents, 'AiStudyCardDesktopWorkflow must bind aiGenerateCardsDialog via v-model on the shared dialog.');
        $this->assertStringContainsString('@confirm="confirmGenerateCards"', $workflowContents, 'AiStudyCardDesktopWorkflow must wire @confirm to confirmGenerateCards.');
    }

    /**
     * 3. VocabularyBox must reference the AiStudyCardDesktopWorkflow feature island,
     *    which in turn references the shared AiStudyCardGenerateCardsResult (via
     *    AiStudyCardPreviewDialog after Round 4 deep module split).
     *
     *    After Round 4 (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4),
     *    AiStudyCardGenerateCardsResult is imported + rendered by
     *    AiStudyCardPreviewDialog (a presentational sub-component of the workflow).
     *    The workflow passes aiGenerateCardsResult down to PreviewDialog, which
     *    in turn binds it to the Result component. The contract is locked at the
     *    workflow-surface boundary.
     */
    public function test_vocabulary_box_references_shared_result_component(): void
    {
        $boxContents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('<AiStudyCardDesktopWorkflow', $boxContents, 'VocabularyBox must render <AiStudyCardDesktopWorkflow> in template.');

        // The workflow must render <AiStudyCardPreviewDialog> and pass the result
        // payload + go-to-sense-reviews handler down to it.
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString('<AiStudyCardPreviewDialog', $workflowContents, 'AiStudyCardDesktopWorkflow must render <AiStudyCardPreviewDialog>.');
        $this->assertStringContainsString(':ai-generate-cards-result="aiGenerateCardsResult"', $workflowContents, 'AiStudyCardDesktopWorkflow must pass aiGenerateCardsResult to AiStudyCardPreviewDialog.');
        $this->assertStringContainsString('@go-to-sense-reviews="goToSenseReviews"', $workflowContents, 'AiStudyCardDesktopWorkflow must wire @go-to-sense-reviews to goToSenseReviews on AiStudyCardPreviewDialog.');

        // The shared result component is now imported + rendered by AiStudyCardPreviewDialog.
        $previewDialogPath = resource_path('js/components/Text/AiStudyCardPreviewDialog.vue');
        $this->assertFileExists($previewDialogPath, 'AiStudyCardPreviewDialog.vue must exist.');
        $previewContents = file_get_contents($previewDialogPath);
        $this->assertStringContainsString("import AiStudyCardGenerateCardsResult from './AiStudyCardGenerateCardsResult.vue'", $previewContents, 'AiStudyCardPreviewDialog must import AiStudyCardGenerateCardsResult.');
        $this->assertStringContainsString('<AiStudyCardGenerateCardsResult', $previewContents, 'AiStudyCardPreviewDialog must render <AiStudyCardGenerateCardsResult> in template.');
        $this->assertStringContainsString(':result="aiGenerateCardsResult"', $previewContents, 'AiStudyCardPreviewDialog must bind aiGenerateCardsResult to the shared result component.');
        $this->assertStringContainsString("@go-to-sense-reviews=\"\$emit('go-to-sense-reviews')\"", $previewContents, 'AiStudyCardPreviewDialog must bubble go-to-sense-reviews event.');
    }

    /**
     * 4. AiStudyCardDesktopWorkflow (the feature island) must import the shared
     *    service helpers (buildGenerateCardItems, filterConfirmedGenerateCardItems,
     *    generateAiStudyCards). VocabularyBox.vue no longer imports them directly.
     */
    public function test_vocabulary_box_imports_shared_service_helpers(): void
    {
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString("from '../../services/AiStudyCardGenerateCardsService", $workflowContents, 'AiStudyCardDesktopWorkflow must import from AiStudyCardGenerateCardsService.');
        $this->assertStringContainsString('buildGenerateCardItems', $workflowContents, 'AiStudyCardDesktopWorkflow must import buildGenerateCardItems.');
        $this->assertStringContainsString('filterConfirmedGenerateCardItems', $workflowContents, 'AiStudyCardDesktopWorkflow must import filterConfirmedGenerateCardItems.');
        $this->assertStringContainsString('generateAiStudyCards', $workflowContents, 'AiStudyCardDesktopWorkflow must import generateAiStudyCards.');

        // VocabularyBox must NOT import the shared service helpers directly anymore.
        // Match both './' and '../../' prefix variants to be robust.
        $boxContents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringNotContainsString('services/AiStudyCardGenerateCardsService', $boxContents, 'VocabularyBox must NOT import AiStudyCardGenerateCardsService directly — use <AiStudyCardDesktopWorkflow> instead.');
    }

    /**
     * 5. "中文释义（必填）" copy — sense_zh required. Now lives in the shared dialog.
     */
    public function test_shared_dialog_sense_zh_is_required(): void
    {
        $contents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('中文释义（必填）', $contents, 'AiStudyCardGenerateCardsDialog must mark sense_zh as required.');
        $this->assertStringContainsString('label="中文释义（必填）"', $contents, 'AiStudyCardGenerateCardsDialog must have sense_zh field labeled as required.');
    }

    /**
     * 6. "英文解释（可选，可留空）" copy — sense_en nullable. Now lives in the shared dialog.
     */
    public function test_shared_dialog_sense_en_is_optional(): void
    {
        $contents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('英文解释（可选，可留空）', $contents, 'AiStudyCardGenerateCardsDialog must mark sense_en as optional.');
        $this->assertStringContainsString('可留空，后续再补', $contents, 'AiStudyCardGenerateCardsDialog must indicate sense_en can be left empty.');
    }

    /**
     * 7. AI reason must only be displayed as reference, never auto-filled into sense_zh.
     *    Now lives in the shared dialog + shared service.
     */
    public function test_ai_reason_not_auto_filled_into_sense_zh(): void
    {
        $dialogContents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('推荐理由（参考说明，不是释义）', $dialogContents, 'AiStudyCardGenerateCardsDialog must label AI reason as reference, not definition.');
        $this->assertStringContainsString('推荐理由不是释义，请填写中文释义', $dialogContents, 'AiStudyCardGenerateCardsDialog must warn user that reason is not a definition.');
        $this->assertStringContainsString('AI 推荐理由只解释为什么推荐这个词，不等于中文释义', $dialogContents, 'AiStudyCardGenerateCardsDialog must show stronger AI reason warning.');
        $this->assertStringContainsString('请根据上下文填写中文释义；推荐理由不会自动保存', $dialogContents, 'AiStudyCardGenerateCardsDialog must remind user to write their own Chinese definition.');
        $this->assertStringContainsString('hasAiRecommendedItems()', $dialogContents, 'AiStudyCardGenerateCardsDialog must detect AI recommended items for warning copy.');

        // V5 hardening: ai_recommended item's sense_zh must start empty, not pre-filled from reason.
        // This rule now lives in the shared service (single source of truth).
        $serviceContents = file_get_contents($this->servicePath);
        $this->assertStringContainsString("reason: item.reason || '', // reference display only", $serviceContents, 'AiStudyCardGenerateCardsService must store reason separately, not in sense_zh.');
        $this->assertStringContainsString("sense_zh: '', // user must input", $serviceContents, 'AiStudyCardGenerateCardsService must initialize sense_zh as empty for ai_recommended items.');
        // Guard against accidental pre-fill: the service must NOT assign reason to sense_zh.
        $this->assertStringNotContainsString('sense_zh: item.reason', $serviceContents, 'Service must NOT auto-fill sense_zh from reason.');
        $this->assertStringNotContainsString('sense_zh: item.reason', $dialogContents, 'Dialog must NOT auto-fill sense_zh from reason.');
    }

    /**
     * 9. Result section must show source binding status. Now lives in the shared result component.
     */
    public function test_shared_result_section_shows_source_binding_status(): void
    {
        $contents = file_get_contents($this->resultPath);
        $this->assertStringContainsString('occurrence_created', $contents, 'AiStudyCardGenerateCardsResult must reference occurrence_created.');
        $this->assertStringContainsString('source_binding_status', $contents, 'AiStudyCardGenerateCardsResult must reference source_binding_status.');
        $this->assertStringContainsString('生成学习卡结果', $contents, 'AiStudyCardGenerateCardsResult must show "生成学习卡结果" section title.');
    }

    /**
     * 10. "进入 /reviews/senses 复习" entry must exist. Now lives in the shared result component,
     *     and the workflow component (not VocabularyBox) owns the goToSenseReviews
     *     method + navigation.
     */
    public function test_vocabulary_box_contains_reviews_senses_entry(): void
    {
        $resultContents = file_get_contents($this->resultPath);
        $this->assertStringContainsString('进入 /reviews/senses 复习', $resultContents, 'AiStudyCardGenerateCardsResult must show entry to /reviews/senses.');
        $this->assertStringContainsString("@click=\"\$emit('go-to-sense-reviews')\"", $resultContents, 'AiStudyCardGenerateCardsResult must emit go-to-sense-reviews.');

        // After Round 2, the goToSenseReviews method lives in AiStudyCardDesktopWorkflow.
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString('goToSenseReviews', $workflowContents, 'AiStudyCardDesktopWorkflow must have goToSenseReviews method.');
        $this->assertStringContainsString("window.location.href = '/reviews/senses'", $workflowContents, 'AiStudyCardDesktopWorkflow must navigate to /reviews/senses.');

        // VocabularyBox must NOT own the V5 navigation method anymore.
        $boxContents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringNotContainsString('goToSenseReviews', $boxContents, 'VocabularyBox must NOT own goToSenseReviews — it now lives in AiStudyCardDesktopWorkflow.');
    }

    /**
     * 11. No external AI provider calls — only local /ai-study-card/* endpoints.
     *     Check VocabularyBox, the workflow component, and the shared service.
     */
    public function test_v5_surface_has_no_external_ai_provider_calls(): void
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

        foreach ([$this->vocabularyBoxPath, $this->workflowPath, $this->dialogPath, $this->resultPath, $this->servicePath] as $path) {
            if (!file_exists($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, basename($path) . " must not call external AI provider: $pattern");
            }
        }

        // V5 must only POST to local /ai-study-card/generate-cards endpoint.
        // The POST now lives in the shared service.
        $serviceContents = file_get_contents($this->servicePath);
        $this->assertStringContainsString("axios.post('/ai-study-card/generate-cards'", $serviceContents, 'AiStudyCardGenerateCardsService must POST to local /ai-study-card/generate-cards.');
    }

    /**
     * 12. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls
     *     anywhere in the V5 shared surface (including the workflow component).
     */
    public function test_v5_surface_has_no_review_log_fsrs_rating_or_legacy_word_card_calls(): void
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

        foreach ([$this->vocabularyBoxPath, $this->workflowPath, $this->dialogPath, $this->resultPath, $this->servicePath] as $path) {
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
     * 13. openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews methods
     *     must exist in AiStudyCardDesktopWorkflow (the feature island), NOT in
     *     VocabularyBox. After Round 2, VocabularyBox no longer owns V5 methods.
     */
    public function test_vocabulary_box_has_v5_methods(): void
    {
        $workflowContents = file_get_contents($this->workflowPath);
        $this->assertStringContainsString('openGenerateCardsDialog()', $workflowContents, 'AiStudyCardDesktopWorkflow must define openGenerateCardsDialog method.');
        $this->assertStringContainsString('confirmGenerateCards()', $workflowContents, 'AiStudyCardDesktopWorkflow must define confirmGenerateCards method.');
        $this->assertStringContainsString('goToSenseReviews()', $workflowContents, 'AiStudyCardDesktopWorkflow must define goToSenseReviews method.');

        // VocabularyBox must NOT own these V5 methods anymore.
        $boxContents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringNotContainsString('openGenerateCardsDialog()', $boxContents, 'VocabularyBox must NOT define openGenerateCardsDialog — it now lives in AiStudyCardDesktopWorkflow.');
        $this->assertStringNotContainsString('confirmGenerateCards()', $boxContents, 'VocabularyBox must NOT define confirmGenerateCards — it now lives in AiStudyCardDesktopWorkflow.');
        $this->assertStringNotContainsString('goToSenseReviews()', $boxContents, 'VocabularyBox must NOT define goToSenseReviews — it now lives in AiStudyCardDesktopWorkflow.');
    }

    /**
     * 14. V5 data fields must exist in AiStudyCardDesktopWorkflow (the feature
     *     island), NOT in VocabularyBox. After Round 2, VocabularyBox no longer
     *     owns V5 state.
     */
    public function test_vocabulary_box_has_v5_data_fields(): void
    {
        $workflowContents = file_get_contents($this->workflowPath);
        $requiredFields = [
            'aiGenerateCardsDialog:',
            'aiGenerateCardsItems:',
            'aiGenerateCardsLoading:',
            'aiGenerateCardsError:',
            'aiGenerateCardsResult:',
        ];
        foreach ($requiredFields as $field) {
            $this->assertStringContainsString($field, $workflowContents, "AiStudyCardDesktopWorkflow must define data field: $field");
        }

        // VocabularyBox must NOT own these V5 data fields anymore.
        $boxContents = file_get_contents($this->vocabularyBoxPath);
        foreach ($requiredFields as $field) {
            $this->assertStringNotContainsString($field, $boxContents, "VocabularyBox must NOT define data field: $field — it now lives in AiStudyCardDesktopWorkflow.");
        }
    }

    /**
     * 15. V5 safety copy: "这不是 AI 自动调用" must be displayed in shared components.
     */
    public function test_shared_components_contain_v5_safety_copy(): void
    {
        $dialogContents = file_get_contents($this->dialogPath);
        $resultContents = file_get_contents($this->resultPath);
        $this->assertStringContainsString('这不是 AI 自动调用', $dialogContents, 'AiStudyCardGenerateCardsDialog must display V5 safety copy.');
        $this->assertStringContainsString('这不是 AI 自动调用', $resultContents, 'AiStudyCardGenerateCardsResult must display V5 safety copy.');
    }

    /**
     * 16. V5 result section must show created / skipped / duplicate / failed counts.
     *     Now lives in the shared result component.
     */
    public function test_shared_result_section_shows_four_category_counts(): void
    {
        $contents = file_get_contents($this->resultPath);
        $this->assertStringContainsString('created_count', $contents, 'AiStudyCardGenerateCardsResult must show created count.');
        $this->assertStringContainsString('skipped_count', $contents, 'AiStudyCardGenerateCardsResult must show skipped count.');
        $this->assertStringContainsString('duplicate_count', $contents, 'AiStudyCardGenerateCardsResult must show duplicate count.');
        $this->assertStringContainsString('failed_count', $contents, 'AiStudyCardGenerateCardsResult must show failed count.');
    }

    /**
     * 17. V5 dialog must show explicit "将生成 / 将跳过" counts before confirm so
     *     the user knows exactly how many cards will be created and how many
     *     items will be dropped before clicking the confirm button.
     *
     * The copy must reflect the real filter behavior in
     * filterConfirmedGenerateCardItems() which drops items with empty sense_zh.
     */
    public function test_shared_dialog_shows_explicit_generate_and_skip_counts(): void
    {
        $contents = file_get_contents($this->dialogPath);
        $this->assertStringContainsString('将生成', $contents, 'AiStudyCardGenerateCardsDialog must show "将生成" count before confirm.');
        $this->assertStringContainsString('将跳过', $contents, 'AiStudyCardGenerateCardsDialog must show "将跳过" count before confirm.');
        $this->assertStringContainsString('filledCount', $contents, 'AiStudyCardGenerateCardsDialog must compute filledCount.');
        $this->assertStringContainsString('skippedCount', $contents, 'AiStudyCardGenerateCardsDialog must compute skippedCount.');
        $this->assertStringContainsString('canConfirm', $contents, 'AiStudyCardGenerateCardsDialog must compute canConfirm gate.');
        // The legacy "已填 X 项" copy must be replaced with the explicit generate/skip copy
        // to avoid ambiguity about what the confirm button actually does.
        $this->assertStringNotContainsString('已填 ', $contents, 'AiStudyCardGenerateCardsDialog must replace ambiguous "已填" copy with explicit "将生成/将跳过" counts.');
    }

    /**
     * 18. V5 dialog must disable the confirm button and guide the user when
     *     zero Chinese definitions are filled. This prevents the user from
     *     clicking "确认生成学习卡" when no cards would actually be created,
     *     which previously caused confusion.
     */
    public function test_shared_dialog_disables_confirm_when_zero_definitions_filled(): void
    {
        $contents = file_get_contents($this->dialogPath);
        // Button must be gated by canConfirm (filledCount > 0), not just items.length > 0.
        $this->assertStringContainsString(':disabled="!canConfirm"', $contents, 'AiStudyCardGenerateCardsDialog confirm button must be disabled when canConfirm is false (0 filled).');
        // Button copy must guide the user when 0 filled.
        $this->assertStringContainsString('请至少填写 1 个中文释义', $contents, 'AiStudyCardGenerateCardsDialog must guide user to fill at least 1 definition when 0 filled.');
        // Dynamic button copy must reflect actual generation count.
        $this->assertStringContainsString('确认生成', $contents, 'AiStudyCardGenerateCardsDialog button copy must reflect actual generation count.');
        // Warning alert must appear when 0 filled to make the blockage clear.
        $this->assertStringContainsString('还没有填写任何中文释义', $contents, 'AiStudyCardGenerateCardsDialog must show warning alert when 0 definitions filled.');
    }

    /**
     * 19. V5 dialog must show a per-candidate "将生成 / 将跳过" status chip so
     *     the user can see at a glance which items will be generated and which
     *     will be skipped, without having to inspect each input field.
     *
     * The per-item status must be derived from whether item.sense_zh is
     * non-empty (matching the bottom counts and the backend filter in
     * filterConfirmedGenerateCardItems()).
     */
    public function test_shared_dialog_shows_per_candidate_generate_skip_status(): void
    {
        $contents = file_get_contents($this->dialogPath);
        // Per-item status chip copy must exist for both states.
        $this->assertStringContainsString('将生成', $contents, 'AiStudyCardGenerateCardsDialog must show "将生成" status on filled candidates.');
        $this->assertStringContainsString('将跳过', $contents, 'AiStudyCardGenerateCardsDialog must show "将跳过" status on empty candidates.');
        // The isFilled method must exist and be bound to the chip, so the
        // per-item status stays in sync with the bottom counts.
        $this->assertStringContainsString('isFilled(item)', $contents, 'AiStudyCardGenerateCardsDialog must bind per-item status to isFilled(item).');
        $this->assertStringContainsString('isFilled(item) ? \'success\' : \'warning\'', $contents, 'AiStudyCardGenerateCardsDialog must color the status chip success/warning based on isFilled.');
        $this->assertStringContainsString('isFilled(item) ? \'将生成\' : \'将跳过\'', $contents, 'AiStudyCardGenerateCardsDialog must render the status chip copy from isFilled.');
        // The isFilled method definition must live in the dialog (single source
        // of truth for per-item status), checking sense_zh non-empty.
        $this->assertStringContainsString('isFilled(item) {', $contents, 'AiStudyCardGenerateCardsDialog must define isFilled(item) method.');
        $this->assertStringContainsString('item.sense_zh', $contents, 'AiStudyCardGenerateCardsDialog isFilled must inspect item.sense_zh.');
    }
}
