<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GM52-AIStudyCardV5-VocabularyBoxNarrowScreenParity-1000-1
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
 * Covers (narrow-screen VocabularyBox.vue only — VocabularySideBox.vue is
 * already covered by AiStudyCardPendingItemTest):
 *  1. "生成学习卡" entry button exists;
 *  2. "确认生成学习卡" dialog exists;
 *  3. "中文释义（必填）" copy exists (sense_zh required);
 *  4. "英文解释（可选，可留空）" copy exists (sense_en nullable);
 *  5. AI reason displayed as "推荐理由（参考说明，不是释义）" — never auto-filled into sense_zh;
 *  6. Result section shows source binding status (occurrence_created / source_binding_status);
 *  7. "进入 /reviews/senses 复习" entry exists;
 *  8. No external AI provider calls (no axios.post to openai/deepseek/etc.);
 *  9. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls;
 * 10. openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews methods exist.
 */
class VocabularyBoxV5UiGuardTest extends TestCase
{
    private string $vocabularyBoxPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vocabularyBoxPath = resource_path('js/components/Text/VocabularyBox.vue');
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
     * 2. "确认生成学习卡" dialog must exist.
     */
    public function test_vocabulary_box_contains_generate_cards_confirmation_dialog(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('v-model="aiGenerateCardsDialog"', $contents, 'VocabularyBox must have aiGenerateCardsDialog v-dialog.');
        $this->assertStringContainsString('确认生成学习卡', $contents, 'VocabularyBox must show "确认生成学习卡" dialog title.');
    }

    /**
     * 3. "中文释义（必填）" copy — sense_zh required.
     */
    public function test_vocabulary_box_sense_zh_is_required(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('中文释义（必填）', $contents, 'VocabularyBox must mark sense_zh as required.');
        $this->assertStringContainsString("label=\"中文释义（必填）\"", $contents, 'VocabularyBox must have sense_zh field labeled as required.');
    }

    /**
     * 4. "英文解释（可选，可留空）" copy — sense_en nullable.
     */
    public function test_vocabulary_box_sense_en_is_optional(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('英文解释（可选，可留空）', $contents, 'VocabularyBox must mark sense_en as optional.');
        $this->assertStringContainsString('可留空，后续再补', $contents, 'VocabularyBox must indicate sense_en can be left empty.');
    }

    /**
     * 5. AI reason must only be displayed as reference, never auto-filled into sense_zh.
     */
    public function test_vocabulary_box_ai_reason_not_auto_filled_into_sense_zh(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('推荐理由（参考说明，不是释义）', $contents, 'VocabularyBox must label AI reason as reference, not definition.');
        $this->assertStringContainsString('推荐理由不是释义，请填写中文释义', $contents, 'VocabularyBox must warn user that reason is not a definition.');

        // V5 hardening: ai_recommended item's sense_zh must start empty, not pre-filled from reason.
        // We verify by checking the openGenerateCardsDialog method body.
        $this->assertStringContainsString("reason: item.reason || '', // 仅作为参考说明展示", $contents, 'VocabularyBox must store reason separately, not in sense_zh.');
        $this->assertStringContainsString("sense_zh: '', // 用户需要输入", $contents, 'VocabularyBox must initialize sense_zh as empty for ai_recommended items.');
    }

    /**
     * 6. Result section must show source binding status (occurrence_created / source_binding_status).
     */
    public function test_vocabulary_box_result_section_shows_source_binding_status(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('occurrence_created', $contents, 'VocabularyBox result section must reference occurrence_created.');
        $this->assertStringContainsString('source_binding_status', $contents, 'VocabularyBox result section must reference source_binding_status.');
        $this->assertStringContainsString('生成学习卡结果', $contents, 'VocabularyBox must show "生成学习卡结果" section title.');
    }

    /**
     * 7. "进入 /reviews/senses 复习" entry must exist.
     */
    public function test_vocabulary_box_contains_reviews_senses_entry(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('进入 /reviews/senses 复习', $contents, 'VocabularyBox must show entry to /reviews/senses.');
        $this->assertStringContainsString('goToSenseReviews', $contents, 'VocabularyBox must have goToSenseReviews method.');
        $this->assertStringContainsString("window.location.href = '/reviews/senses'", $contents, 'VocabularyBox must navigate to /reviews/senses.');
    }

    /**
     * 8. No external AI provider calls — only local /ai-study-card/* endpoints.
     */
    public function test_vocabulary_box_has_no_external_ai_provider_calls(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $forbiddenPatterns = [
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.x.ai',
            'https://api.',
            'http://api.',
        ];
        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString($pattern, $contents, "VocabularyBox must not call external AI provider: $pattern");
        }

        // V5 must only POST to local /ai-study-card/generate-cards endpoint.
        $this->assertStringContainsString("axios.post('/ai-study-card/generate-cards'", $contents, 'VocabularyBox V5 must POST to local /ai-study-card/generate-cards.');
    }

    /**
     * 9. No ReviewLog / FSRS rating / legacy word ReviewCard creation calls.
     */
    public function test_vocabulary_box_has_no_review_log_fsrs_rating_or_legacy_word_card_calls(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $forbiddenPatterns = [
            '/review-log',
            '/reviews/rate',
            '/reviews/senses/',
            '/fsrs',
            'target_type: \'word\'',
            'target_type: "word"',
            "target_type' => 'word'",
            'ReviewLog',
            'reviewLog',
        ];
        foreach ($forbiddenPatterns as $pattern) {
            $this->assertStringNotContainsString($pattern, $contents, "VocabularyBox must not reference forbidden pattern: $pattern");
        }
    }

    /**
     * 10. openGenerateCardsDialog / confirmGenerateCards / goToSenseReviews methods must exist.
     */
    public function test_vocabulary_box_has_v5_methods(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('openGenerateCardsDialog()', $contents, 'VocabularyBox must define openGenerateCardsDialog method.');
        $this->assertStringContainsString('confirmGenerateCards()', $contents, 'VocabularyBox must define confirmGenerateCards method.');
        $this->assertStringContainsString('goToSenseReviews()', $contents, 'VocabularyBox must define goToSenseReviews method.');
    }

    /**
     * 11. V5 data fields must exist (aiGenerateCardsDialog / aiGenerateCardsItems / etc.).
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
     * 12. V5 safety copy: "这不是 AI 自动调用" must be displayed in both dialog and result.
     */
    public function test_vocabulary_box_contains_v5_safety_copy(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('这不是 AI 自动调用', $contents, 'VocabularyBox must display V5 safety copy.');
    }

    /**
     * 13. V5 result section must show created / skipped / duplicate / failed counts.
     */
    public function test_vocabulary_box_result_section_shows_four_category_counts(): void
    {
        $contents = file_get_contents($this->vocabularyBoxPath);
        $this->assertStringContainsString('created_count', $contents, 'VocabularyBox result must show created count.');
        $this->assertStringContainsString('skipped_count', $contents, 'VocabularyBox result must show skipped count.');
        $this->assertStringContainsString('duplicate_count', $contents, 'VocabularyBox result must show duplicate count.');
        $this->assertStringContainsString('failed_count', $contents, 'VocabularyBox result must show failed count.');
    }
}
