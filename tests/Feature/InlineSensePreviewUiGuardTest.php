<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GLM-ReadingInlinePreview-First-1 + GLM-ReadingInlineConfirmationPersistence-1000-1
 *
 * Frontend / UI guard tests for the inline sense preview panel.
 *
 * The project currently has no dedicated Vue component test runner, so these
 * are PHP source-string guards that scan the component source to lock in the
 * UI contract. This is a documented limitation — when the project adds a real
 * Vue testing harness (e.g. @vue/test-utils), these guards should be
 * upgraded to true component render assertions.
 *
 * Covers:
 *  1. preview 文案 exists in InlineSensePreviewPanel.vue;
 *  2. safety 文案 exists ("不会写入复习记录" / "不会改变 FSRS" / "这不是复习评分");
 *  3. persisted-echo 文案 exists ("已保存：是这个意思" / "已保存：不是这个意思");
 *  4. no "立即评分" copy;
 *  5. no "写入复习记录" copy;
 *  6. no "AI 已判断" copy;
 *  7. no Good / Easy / Hard / Again rating buttons;
 *  8. component POSTs only to /senses/inline-confirmation (the safe writer),
 *     never to /reviews/rate, /reviews/senses/.../rate, /review-log, /fsrs;
 *  9. legacy entry copy not re-introduced.
 */
class InlineSensePreviewUiGuardTest extends TestCase
{
    private string $panelPath;
    private string $wordSensesListPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->panelPath = resource_path('js/components/Text/InlineSensePreviewPanel.vue');
        $this->wordSensesListPath = resource_path('js/components/Text/WordSensesList.vue');
    }

    public function test_inline_sense_preview_panel_file_exists(): void
    {
        $this->assertFileExists($this->panelPath, 'InlineSensePreviewPanel.vue must exist.');
    }

    public function test_panel_contains_preview_copy(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString('候选预览', $contents, 'panel must show preview title.');
        $this->assertStringContainsString('当前词形', $contents, 'panel must show current surface label.');
        $this->assertStringContainsString('词元', $contents, 'panel must show lemma label.');
    }

    public function test_panel_contains_safety_copy(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString('不会写入复习记录', $contents, 'panel must state no review log written.');
        $this->assertStringContainsString('不会改变 FSRS', $contents, 'panel must state no FSRS change.');
        $this->assertStringContainsString('这不是复习评分', $contents, 'panel must state this is not a review rating.');
    }

    public function test_panel_contains_persisted_echo_copy(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString('已保存：是这个意思', $contents, 'panel must show "已保存：是这个意思" echo.');
        $this->assertStringContainsString('已保存：不是这个意思', $contents, 'panel must show "已保存：不是这个意思" echo.');
    }

    public function test_panel_does_not_contain_immediate_rating_copy(): void
    {
        $contents = file_get_contents($this->panelPath);
        $blocked = ['立即评分', '立即复习', '马上评分'];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'panel must not show immediate rating copy.');
        }
    }

    public function test_panel_does_not_contain_fsrs_rating_buttons(): void
    {
        $contents = file_get_contents($this->panelPath);
        // Block FSRS rating button copy. The confirmation buttons must remain
        // "是这个意思 / 不是这个意思", NOT Good / Easy / Hard / Again.
        $blocked = ['Good', 'Easy', 'Hard', 'Again', 'again', 'easy', 'hard', 'good'];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'panel must not contain FSRS rating button copy [' . $copy . '].');
        }
    }

    public function test_panel_does_not_contain_write_review_log_copy(): void
    {
        $contents = file_get_contents($this->panelPath);
        // Block affirmative "write review log" copy. Safety copy like
        // "不会写入复习记录" is allowed and verified in a separate test.
        $blocked = ['立即写入复习记录', '确认写入复习记录', '已写入复习记录'];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'panel must not show affirmative write-review-log copy.');
        }
    }

    public function test_panel_does_not_contain_ai_judgment_copy(): void
    {
        $contents = file_get_contents($this->panelPath);
        $blocked = ['AI 已判断', 'AI 判断', 'AI已判断', '调用 AI', '调用AI', 'AI 真实判断'];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'panel must not show AI-judgment copy.');
        }
    }

    public function test_panel_does_not_trigger_rating_or_review_or_fsrs_routes(): void
    {
        $contents = file_get_contents($this->panelPath);
        // Block actual API paths and service method names that would indicate
        // the panel triggers rating / review-log / fsrs writes. We do NOT
        // block bare class names like "ReviewLog" / "ReviewCard" because
        // those appear in safety comments ("does NOT write ReviewLog").
        $blockedRoutes = [
            '/reviews/senses/',
            '/reviews/rate',
            '/review-log',
            '/reviewlog',
            '/fsrs',
            '/review-cards',
            '/review_cards',
            'reviewCardId',
            'recordReview',
            'fsrsSchedulingService',
            'FsrsSchedulingService',
        ];
        foreach ($blockedRoutes as $route) {
            $this->assertStringNotContainsString($route, $contents, 'panel must not trigger rating / review-log / fsrs route [' . $route . '].');
        }
    }

    public function test_panel_persists_choice_only_via_safe_endpoint(): void
    {
        $contents = file_get_contents($this->panelPath);
        // The choice buttons must POST only to /senses/inline-confirmation.
        $this->assertStringContainsString('/senses/inline-confirmation', $contents, 'panel must persist via /senses/inline-confirmation.');
        $this->assertStringContainsString('axios.post', $contents, 'panel must use axios.post to persist choice.');

        // It must NOT POST to any other backend endpoint.
        $this->assertStringNotContainsString("axios.post('/reviews", $contents, 'panel must not POST to /reviews.');
        $this->assertStringNotContainsString("axios.post('/review-log", $contents, 'panel must not POST to /review-log.');
        $this->assertStringNotContainsString("axios.post('/fsrs", $contents, 'panel must not POST to /fsrs.');
    }

    public function test_panel_contains_is_this_meaning_and_not_this_meaning_buttons(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString('是这个意思', $contents, 'panel must have "是这个意思" button.');
        $this->assertStringContainsString('不是这个意思', $contents, 'panel must have "不是这个意思" button.');
    }

    public function test_word_senses_list_embeds_inline_sense_preview_panel(): void
    {
        $contents = file_get_contents($this->wordSensesListPath);
        $this->assertStringContainsString('InlineSensePreviewPanel', $contents, 'WordSensesList must import InlineSensePreviewPanel.');
        $this->assertStringContainsString('<inline-sense-preview-panel', $contents, 'WordSensesList must render <inline-sense-preview-panel>.');
    }

    public function test_word_senses_list_passes_chapter_and_sentence_index(): void
    {
        $contents = file_get_contents($this->wordSensesListPath);
        $this->assertStringContainsString(':chapter-id', $contents, 'WordSensesList must pass chapter-id to InlineSensePreviewPanel.');
        $this->assertStringContainsString(':sentence-index', $contents, 'WordSensesList must pass sentence-index to InlineSensePreviewPanel.');
    }

    public function test_legacy_entry_copy_not_reintroduced_in_lookup_components(): void
    {
        $componentPaths = [
            resource_path('js/components/Text/WordSensesList.vue'),
            resource_path('js/components/Text/VocabularySideBox.vue'),
            resource_path('js/components/Text/VocabularyBox.vue'),
            resource_path('js/components/Text/InlineSensePreviewPanel.vue'),
        ];

        $blockedCopy = [
            '旧词条释义',
            '旧版释义',
            '旧版示意',
            'legacy word review',
        ];

        foreach ($componentPaths as $path) {
            if (!file_exists($path)) {
                $this->fail("Missing component file: {$path}");
            }
            $contents = file_get_contents($path);
            foreach ($blockedCopy as $copy) {
                $this->assertStringNotContainsString($copy, $contents, $path . ' should not expose legacy entry copy.');
            }
        }
    }
}
