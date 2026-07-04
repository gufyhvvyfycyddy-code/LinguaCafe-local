<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GLM-ReadingInlineConfirmationManagementSurface-1000-1 (sub-stage 8, +100%)
 *
 * Frontend / UI guard tests for ReadingInlineConfirmationManage.vue.
 *
 * The project currently has no dedicated Vue component test runner, so these
 * are PHP source-string guards that scan the component source to lock in the
 * UI contract. This is a documented limitation — when the project adds a real
 * Vue testing harness (e.g. @vue/test-utils), these guards should be
 * upgraded to true component render assertions.
 *
 * Covers (per task spec sub-stage 8):
 *  1. "阅读中词义确认记录" title exists;
 *  2. "不是复习评分" safety copy exists;
 *  3. "是这个意思" copy exists;
 *  4. "不是这个意思" copy exists;
 *  5. "撤销这条记录" copy exists;
 *  6. "撤销不是删除词义" or equivalent copy exists;
 *  7. no Good / Easy / Hard / Again rating buttons;
 *  8. no rating route;
 *  9. no ReviewLog route;
 * 10. no FSRS write route;
 * 11. no "复习失败" copy as revoke meaning;
 * 12. no "忘记了" copy as revoke meaning;
 * 13. no "删除词义" copy as revoke meaning;
 * 14. no batch-revoke UI.
 */
class ReadingInlineConfirmationManageUiGuardTest extends TestCase
{
    private string $managePath;
    private string $panelPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->managePath = resource_path('js/components/Senses/ReadingInlineConfirmationManage.vue');
        $this->panelPath = resource_path('js/components/Text/InlineSensePreviewPanel.vue');
    }

    // ==================== 1. Title exists ====================

    public function test_manage_page_file_exists(): void
    {
        $this->assertFileExists($this->managePath, 'ReadingInlineConfirmationManage.vue must exist.');
    }

    public function test_manage_page_contains_title_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('阅读中词义确认记录', $contents, 'manage page must show "阅读中词义确认记录" title.');
    }

    // ==================== 2. "不是复习评分" safety copy ====================

    public function test_manage_page_contains_not_review_rating_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('这不是复习评分', $contents, 'manage page must state this is not a review rating.');
    }

    public function test_manage_page_contains_no_review_log_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不会写入复习记录', $contents, 'manage page must state no review log written.');
    }

    public function test_manage_page_contains_no_fsrs_change_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不会改变复习进度', $contents, 'manage page must state no FSRS change.');
    }

    // ==================== 3 & 4. "是这个意思" / "不是这个意思" ====================

    public function test_manage_page_contains_is_this_meaning_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('是这个意思', $contents, 'manage page must show "是这个意思" copy.');
    }

    public function test_manage_page_contains_not_this_meaning_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不是这个意思', $contents, 'manage page must show "不是这个意思" copy.');
    }

    // ==================== 5. "撤销这条阅读判断" (updated by UndoHotkey task) ====================

    public function test_manage_page_contains_revoke_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        // OpenCode-ReadingInlineConfirmationUndoHotkey-800-1: button copy
        // changed from "撤销这条记录" to "撤销这条阅读判断" to clarify that
        // the action only removes a reading-inline judgment, not a review card
        // or a word sense.
        $this->assertStringContainsString('撤销这条阅读判断', $contents, 'manage page must show "撤销这条阅读判断" button.');
    }

    public function test_manage_page_contains_revoke_dialog(): void
    {
        $contents = file_get_contents($this->managePath);
        // Revoke must be guarded by a confirmation dialog.
        $this->assertStringContainsString('撤销这条阅读中确认记录？', $contents, 'manage page must show revoke confirmation dialog title.');
        $this->assertStringContainsString('confirmRevoke', $contents, 'manage page must have confirmRevoke method.');
        $this->assertStringContainsString('openRevokeDialog', $contents, 'manage page must have openRevokeDialog method.');
    }

    // ==================== 6. "撤销不是删除词义" ====================

    public function test_manage_page_contains_revoke_not_forget_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        // The revoke semantics must be explicit: NOT forget / NOT review-fail / NOT delete-sense.
        $this->assertStringContainsString('不是忘记', $contents, 'manage page must state revoke is NOT forget.');
        $this->assertStringContainsString('不是复习失败', $contents, 'manage page must state revoke is NOT review failure.');
        $this->assertStringContainsString('不是删除词义', $contents, 'manage page must state revoke is NOT deleting sense.');
    }

    // ==================== 7. No FSRS rating buttons ====================

    public function test_manage_page_does_not_contain_fsrs_rating_buttons(): void
    {
        $contents = file_get_contents($this->managePath);
        // Block FSRS rating button copy. The confirmation buttons must remain
        // "是这个意思 / 不是这个意思", NOT Good / Easy / Hard / Again.
        $blocked = ['Good', 'Easy', 'Hard', 'Again', 'again', 'easy', 'hard', 'good'];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'manage page must not contain FSRS rating button copy [' . $copy . '].');
        }
    }

    // ==================== 8 & 9 & 10. No rating / ReviewLog / FSRS routes ====================

    public function test_manage_page_does_not_trigger_rating_or_review_or_fsrs_routes(): void
    {
        $contents = file_get_contents($this->managePath);
        // Block actual API paths and service method names that would indicate
        // the page triggers rating / review-log / fsrs writes. We do NOT
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
            $this->assertStringNotContainsString($route, $contents, 'manage page must not trigger rating / review-log / fsrs route [' . $route . '].');
        }
    }

    public function test_manage_page_does_not_contain_ai_routes(): void
    {
        $contents = file_get_contents($this->managePath);
        // No real AI calls from the management page.
        // Note: axios.post is now allowed for the undo endpoint
        // (POST /senses/inline-confirmations/undo, added by
        // OpenCode-ReadingInlineConfirmationUndoHotkey-800-1).
        $blocked = ['/ai/', '/openai', '/chatgpt', '/gpt'];
        foreach ($blocked as $route) {
            $this->assertStringNotContainsString($route, $contents, 'manage page must not call AI route [' . $route . '].');
        }
        // Any axios.post must be ONLY to the undo endpoint, never to AI / rating / review-log.
        $this->assertStringContainsString("axios.post('/senses/inline-confirmations/undo'", $contents, 'manage page axios.post must target the undo endpoint.');
        $this->assertStringNotContainsString("axios.post('/ai", $contents, 'manage page must not POST to AI.');
        $this->assertStringNotContainsString("axios.post('/reviews", $contents, 'manage page must not POST to reviews.');
        $this->assertStringNotContainsString("axios.post('/review-log", $contents, 'manage page must not POST to review-log.');
        $this->assertStringNotContainsString("axios.post('/fsrs", $contents, 'manage page must not POST to fsrs.');
    }

    public function test_manage_page_only_calls_safe_endpoints(): void
    {
        $contents = file_get_contents($this->managePath);
        // The page must GET only /senses/inline-confirmations and
        // DELETE only /senses/inline-confirmations/{id}.
        $this->assertStringContainsString("axios.get('/senses/inline-confirmations'", $contents, 'manage page must GET /senses/inline-confirmations.');
        $this->assertStringContainsString('axios.delete', $contents, 'manage page must use axios.delete for revoke.');
        $this->assertStringContainsString('/senses/inline-confirmations/', $contents, 'manage page must DELETE /senses/inline-confirmations/{id}.');
    }

    // ==================== 11 & 12 & 13. No forbidden revoke-meaning copy ====================

    public function test_manage_page_does_not_use_forbidden_revoke_meaning_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        // Revoke must NOT be framed as "复习失败" / "忘记了" / "删除词义".
        // Note: safety copy "不是忘记，不是复习失败，也不是删除词义" is allowed
        // because it explicitly negates these meanings. We only block
        // affirmative uses that frame revoke AS one of these meanings.
        // The affirmative copy patterns blocked here are the kind that would
        // appear in a button label or dialog confirmation if the semantics
        // were wrong (e.g. "撤销即忘记" / "撤销即删除词义").
        $blocked = [
            '撤销即忘记',
            '撤销即删除词义',
            '撤销即复习失败',
            '将删除词义',
            '将忘记',
            '将标记为复习失败',
            '标记为忘记',
            '标记为复习失败',
        ];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'manage page must not frame revoke as [' . $copy . '].');
        }
    }

    // ==================== 14. No batch-revoke UI ====================

    public function test_manage_page_does_not_contain_batch_revoke_ui(): void
    {
        $contents = file_get_contents($this->managePath);
        // No batch-revoke buttons / checkboxes / bulk-actions.
        $blocked = [
            '批量撤销',
            '批量删除',
            '全部撤销',
            '清空全部',
            'bulkRevoke',
            'batchRevoke',
            'selectAll',
            'toggleSelectAll',
            'selectedIds',
            'bulkDelete',
        ];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'manage page must not contain batch-revoke UI [' . $copy . '].');
        }
    }

    // ==================== Cross-component: preview panel links to manage page ====================

    public function test_preview_panel_links_to_manage_page(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString('/senses/inline-confirmations/manage', $contents, 'preview panel must link to management page.');
        $this->assertStringContainsString('查看全部阅读确认记录', $contents, 'preview panel must show "查看全部阅读确认记录" link.');
    }

    public function test_manage_page_contains_back_to_reading_link(): void
    {
        $contents = file_get_contents($this->managePath);
        // Each record must have a "回到阅读页" link to /chapters/read/{id}.
        $this->assertStringContainsString('回到阅读页', $contents, 'manage page must show "回到阅读页" link.');
        $this->assertStringContainsString('/chapters/read/', $contents, 'manage page must link to /chapters/read/{id}.');
    }

    public function test_manage_page_contains_empty_state_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('暂无阅读中词义确认记录', $contents, 'manage page must show empty-state copy.');
    }

    // ================================================================
    // OpenCode-ReadingInlineConfirmationUndoHotkey-800-1 (sub-stage 7)
    // Ctrl+Z undo UI guard tests.
    // ================================================================

    /**
     * The management page must show "按 Ctrl+Z 可恢复" copy after a revoke,
     * so the user knows they can press Ctrl+Z to restore the deleted row.
     */
    public function test_manage_page_contains_undo_hint_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('按 Ctrl+Z 可恢复', $contents, 'manage page must show "按 Ctrl+Z 可恢复" undo hint.');
    }

    /**
     * The management page must register a keydown event listener for Ctrl+Z
     * and must clean it up on component destroy.
     */
    public function test_manage_page_registers_and_removes_keydown_listener(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString("addEventListener('keydown'", $contents, 'manage page must add keydown listener on mount.');
        $this->assertStringContainsString("removeEventListener('keydown'", $contents, 'manage page must remove keydown listener on destroy.');
        $this->assertStringContainsString('handleKeyDown', $contents, 'manage page must define handleKeyDown method.');
    }

    /**
     * The management page must NOT intercept Ctrl+Z when the focus is inside
     * an editable input (input / textarea / select / contenteditable), so the
     * browser's native text-undo still works.
     */
    public function test_manage_page_has_input_guard_for_ctrl_z(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('isFocusInsideEditableInput', $contents, 'manage page must define isFocusInsideEditableInput method.');
    }

    /**
     * The management page must call the undo endpoint via POST, not via
     * any rating / review-log / fsrs route.
     */
    public function test_manage_page_calls_undo_endpoint_safely(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('/senses/inline-confirmations/undo', $contents, 'manage page must call POST /senses/inline-confirmations/undo.');
        // Must NOT call any rating / FSRS / review-log route from the undo path.
        $blocked = ['/reviews/rate', '/reviews/senses/', '/fsrs', '/review-log'];
        foreach ($blocked as $route) {
            $this->assertStringNotContainsString($route, $contents, 'manage page must not call rating/fsrs/review-log route [' . $route . '].');
        }
    }

    /**
     * The management page must clear the undo token after using it, so the
     * user cannot undo the same action twice.
     */
    public function test_manage_page_clears_undo_token_after_use(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('this.undoToken = null', $contents, 'manage page must clear undoToken after use.');
    }

    /**
     * OpenCode-ReadingInlineConfirmationUndoAffordanceFix-1
     *
     * The manage page must show a visible "恢复刚才撤销的阅读判断" button and
     * the "按 Ctrl+Z 或点击下方按钮可恢复" hint when an undo token is available.
     */
    public function test_manage_page_contains_visible_undo_button_and_hint(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('恢复刚才撤销的阅读判断', $contents, 'manage page must show "恢复刚才撤销的阅读判断" undo affordance button.');
        $this->assertStringContainsString('按 Ctrl+Z 或点击下方按钮可恢复', $contents, 'manage page must show "按 Ctrl+Z 或点击下方按钮可恢复" affordance hint.');
        $this->assertStringContainsString('inline-confirmation-undo-button', $contents, 'manage page must have undo button CSS class.');
        $this->assertStringContainsString('inline-confirmation-undo-affordance', $contents, 'manage page must have undo affordance CSS class.');
    }

    /**
     * The preview panel must show "按 Ctrl+Z 可撤销刚才的阅读判断" copy
     * after a store action, so the user knows they can press Ctrl+Z.
     */
    public function test_preview_panel_contains_undo_hint_copy(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString('按 Ctrl+Z 可撤销刚才的阅读判断', $contents, 'preview panel must show undo hint copy.');
    }

    /**
     * The preview panel must register / remove a keydown listener and must
     * have the input-guard method.
     */
    public function test_preview_panel_has_keydown_and_input_guard(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString("addEventListener('keydown'", $contents, 'preview panel must add keydown listener.');
        $this->assertStringContainsString("removeEventListener('keydown'", $contents, 'preview panel must remove keydown listener.');
        $this->assertStringContainsString('handleKeyDown', $contents, 'preview panel must define handleKeyDown.');
        $this->assertStringContainsString('isFocusInsideEditableInput', $contents, 'preview panel must define isFocusInsideEditableInput.');
    }

    /**
     * The preview panel must call the undo endpoint via POST and must NOT
     * call any rating / FSRS / review-log route.
     */
    public function test_preview_panel_calls_undo_endpoint_safely(): void
    {
        $contents = file_get_contents($this->panelPath);
        $this->assertStringContainsString('/senses/inline-confirmations/undo', $contents, 'preview panel must call POST /senses/inline-confirmations/undo.');
        $blocked = ['/reviews/rate', '/reviews/senses/', '/fsrs', '/review-log'];
        foreach ($blocked as $route) {
            $this->assertStringNotContainsString($route, $contents, 'preview panel must not call rating/fsrs/review-log route [' . $route . '].');
        }
    }
}
