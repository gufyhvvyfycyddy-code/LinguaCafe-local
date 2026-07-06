<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GM52-AIStudyCardV5-VocabularyBoxNarrowScreenParity-1000-1
 * + GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1
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
 * After GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1, the V5
 * dialog template and result template live in shared components
 * (AiStudyCardGenerateCardsDialog.vue / AiStudyCardGenerateCardsResult.vue)
 * and the V5 item-building / request logic lives in a shared service
 * (AiStudyCardGenerateCardsService.js). VocabularyBox.vue still owns:
 *   - the "生成学习卡" entry button,
 *   - the dialog open state (aiGenerateCardsDialog),
 *   - the items / loading / error / result data fields,
 *   - the openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews
 *     methods (which now delegate to the shared service).
 *
 * UI-contract assertions that previously scanned VocabularyBox.vue for
 * template copy (e.g. "中文释义（必填）") now scan the shared component
 * files, while VocabularyBox.vue is checked for wiring (component import +
 * template usage). This locks the contract at the new single source of
 * truth and prevents drift.
 *
 * Covers (narrow-screen VocabularyBox.vue + shared V5 components):
 *  1. "生成学习卡" entry button exists in VocabularyBox;
 *  2. VocabularyBox references shared AiStudyCardGenerateCardsDialog;
 *  3. VocabularyBox references shared AiStudyCardGenerateCardsResult;
 *  4. VocabularyBox imports the shared service helpers;
 *  5. "中文释义（必填）" copy exists in the shared dialog;
 *  6. "英文解释（可选，可留空）" copy exists in the shared dialog;
 *  7. AI reason displayed as "推荐理由（参考说明，不是释义）" in the shared dialog;
 *  8. AI reason is NOT auto-filled into sense_zh (service initializes empty);
 *  9. Result section shows source binding status in the shared result component;
 * 10. "进入 /reviews/senses 复习" entry exists in the shared result component;
 * 11. No external AI provider calls anywhere in the V5 shared surface;
 * 12. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls;
 * 13. openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews methods exist;
 * 14. V5 data fields exist in VocabularyBox;
 * 15. V5 safety copy "这不是 AI 自动调用" exists in shared components;
 * 16. Result section shows created / skipped / duplicate / failed counts.
 */
class VocabularyBoxV5UiGuardTest extends TestCase
{
    private string $vocabularyBoxPath;
    private string $dialogPath;
    private string $resultPath;
    private string $servicePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vocabularyBoxPath = resource_path('js/components/Text/VocabularyBox.vue');
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
     */
    public function test_vocabulary_box_contains_generate_cards_entry_button(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('@click="openGenerateCardsDialog"', $contents, 'VocabularyBox must have a button calling openGenerateCardsDialog.');
        $this->assertStringContainsString('生成学习卡', $contents, 'VocabularyBox must show "生成学习卡" button label.');
    }

    /**
     * 2. VocabularyBox must reference the shared AiStudyCardGenerateCardsDialog component
     *    (import + template usage). This is the architecture-convergence contract.
     */
    public function test_vocabulary_box_references_shared_dialog_component(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString("import AiStudyCardGenerateCardsDialog from './AiStudyCardGenerateCardsDialog.vue'", $contents, 'VocabularyBox must import AiStudyCardGenerateCardsDialog.');
        $this->assertStringContainsString('AiStudyCardGenerateCardsDialog', $contents, 'VocabularyBox must register/use AiStudyCardGenerateCardsDialog.');
        $this->assertStringContainsString('<AiStudyCardGenerateCardsDialog', $contents, 'VocabularyBox must render <AiStudyCardGenerateCardsDialog> in template.');
        $this->assertStringContainsString('v-model="aiGenerateCardsDialog"', $contents, 'VocabularyBox must bind aiGenerateCardsDialog via v-model on the shared dialog.');
        $this->assertStringContainsString('@confirm="confirmGenerateCards"', $contents, 'VocabularyBox must wire @confirm to confirmGenerateCards.');
    }

    /**
     * 3. VocabularyBox must reference the shared AiStudyCardGenerateCardsResult component.
     */
    public function test_vocabulary_box_references_shared_result_component(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString("import AiStudyCardGenerateCardsResult from './AiStudyCardGenerateCardsResult.vue'", $contents, 'VocabularyBox must import AiStudyCardGenerateCardsResult.');
        $this->assertStringContainsString('<AiStudyCardGenerateCardsResult', $contents, 'VocabularyBox must render <AiStudyCardGenerateCardsResult> in template.');
        $this->assertStringContainsString(':result="aiGenerateCardsResult"', $contents, 'VocabularyBox must bind aiGenerateCardsResult to the shared result component.');
        $this->assertStringContainsString('@go-to-sense-reviews="goToSenseReviews"', $contents, 'VocabularyBox must wire @go-to-sense-reviews to goToSenseReviews.');
    }

    /**
     * 4. VocabularyBox must import the shared service helpers (buildGenerateCardItems,
     *    filterConfirmedGenerateCardItems, generateAiStudyCards).
     */
    public function test_vocabulary_box_imports_shared_service_helpers(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString("from './../../services/AiStudyCardGenerateCardsService'", $contents, 'VocabularyBox must import from AiStudyCardGenerateCardsService.');
        $this->assertStringContainsString('buildGenerateCardItems', $contents, 'VocabularyBox must import buildGenerateCardItems.');
        $this->assertStringContainsString('filterConfirmedGenerateCardItems', $contents, 'VocabularyBox must import filterConfirmedGenerateCardItems.');
        $this->assertStringContainsString('generateAiStudyCards', $contents, 'VocabularyBox must import generateAiStudyCards.');
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
     *     and VocabularyBox must still own the goToSenseReviews method + navigation.
     */
    public function test_vocabulary_box_contains_reviews_senses_entry(): void
    {
        $resultContents = file_get_contents($this->resultPath);
        $this->assertStringContainsString('进入 /reviews/senses 复习', $resultContents, 'AiStudyCardGenerateCardsResult must show entry to /reviews/senses.');
        $this->assertStringContainsString("@click=\"\$emit('go-to-sense-reviews')\"", $resultContents, 'AiStudyCardGenerateCardsResult must emit go-to-sense-reviews.');

        $boxContents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('goToSenseReviews', $boxContents, 'VocabularyBox must have goToSenseReviews method.');
        $this->assertStringContainsString("window.location.href = '/reviews/senses'", $boxContents, 'VocabularyBox must navigate to /reviews/senses.');
    }

    /**
     * 11. No external AI provider calls — only local /ai-study-card/* endpoints.
     *     Check both VocabularyBox and the shared service.
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

        foreach ([$this->vocabularyBoxPath, $this->dialogPath, $this->resultPath, $this->servicePath] as $path) {
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
     *     anywhere in the V5 shared surface.
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

        foreach ([$this->vocabularyBoxPath, $this->dialogPath, $this->resultPath, $this->servicePath] as $path) {
            $contents = file_get_contents($path);
            foreach ($forbiddenPatterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, basename($path) . " must not reference forbidden pattern: $pattern");
            }
        }
    }

    /**
     * 13. openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews methods must exist.
     */
    public function test_vocabulary_box_has_v5_methods(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('openGenerateCardsDialog()', $contents, 'VocabularyBox must define openGenerateCardsDialog method.');
        $this->assertStringContainsString('confirmGenerateCards()', $contents, 'VocabularyBox must define confirmGenerateCards method.');
        $this->assertStringContainsString('goToSenseReviews()', $contents, 'VocabularyBox must define goToSenseReviews method.');
    }

    /**
     * 14. V5 data fields must exist in VocabularyBox (state ownership stays in parent).
     */
    public function test_vocabulary_box_has_v5_data_fields(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $requiredFields = [
            'aiGenerateCardsDialog:',
            'aiGenerateCardsItems:',
            'aiGenerateCardsLoading:',
            'aiGenerateCardsError:',
            'aiGenerateCardsResult:',
        ];
        foreach ($requiredFields as $field) {
            $this->assertStringContainsString($field, $contents, "VocabularyBox must define data field: $field");
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
}
