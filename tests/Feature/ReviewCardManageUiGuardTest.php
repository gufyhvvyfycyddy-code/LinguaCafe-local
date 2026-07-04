<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * OpenCode-ReviewCardManageDangerCopy-1
 *
 * Frontend / UI guard tests for ReviewCardManage.vue safety copy.
 *
 * The project currently has no dedicated Vue component test runner, so these
 * are PHP source-string guards that scan the component source to lock in the
 * safety dialogs. This is a documented limitation.
 */
class ReviewCardManageUiGuardTest extends TestCase
{
    private string $managePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->managePath = resource_path('js/components/ReviewCards/ReviewCardManage.vue');
    }

    public function test_manage_page_file_exists(): void
    {
        $this->assertFileExists($this->managePath, 'ReviewCardManage.vue must exist.');
    }

    // ==================== Archive dialog ====================

    public function test_archive_dialog_contains_not_delete_sense_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不会删除词义', $contents, 'archive dialog must state no sense deletion.');
    }

    public function test_archive_dialog_contains_not_delete_review_history_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不会删除复习历史', $contents, 'archive dialog must state no review history deletion.');
    }

    // ==================== Restore dialog ====================

    public function test_restore_dialog_contains_not_reset_progress_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不会重置复习进度', $contents, 'restore dialog must state no progress reset.');
    }

    // ==================== Due now dialog ====================

    public function test_due_now_dialog_contains_not_review_rating_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不是一次复习评分', $contents, 'due now dialog must state this is not a review rating.');
    }

    public function test_due_now_dialog_contains_not_write_review_history_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不会写入复习历史', $contents, 'due now dialog must state no review history write.');
    }

    // ==================== Reset dialog ====================

    public function test_reset_dialog_contains_new_card_state_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('新卡状态', $contents, 'reset dialog must mention new card state.');
    }

    public function test_reset_dialog_contains_old_history_preserved_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('旧复习历史会保留', $contents, 'reset dialog must state old review logs are preserved.');
    }

    public function test_reset_dialog_contains_reset_record_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('reset', $contents, 'reset dialog must mention the reset record.');
    }

    // ==================== Delete dialog ====================

    public function test_delete_dialog_contains_review_history_preserved_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('复习历史会保留', $contents, 'delete dialog must state review history is preserved.');
    }

    public function test_delete_dialog_contains_source_records_preserved_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('阅读来源记录会保留', $contents, 'delete dialog must state source records are preserved.');
    }

    public function test_delete_dialog_contains_last_sense_explanation(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('最后一个已确认词义', $contents, 'delete dialog must mention last confirmed sense condition.');
    }

    // ==================== Bulk archive dialog ====================

    public function test_bulk_archive_dialog_contains_only_selected_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('当前勾选', $contents, 'bulk archive dialog must state only selected cards.');
    }

    // ==================== Bulk delete dialog ====================

    public function test_bulk_delete_dialog_contains_not_filter_scope_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('不会按筛选条件全量删除', $contents, 'bulk delete dialog must state no filter-scope operation.');
    }

    // ==================== Forbidden copy ====================

    public function test_manage_page_does_not_contain_forget_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringNotContainsString('忘记这个词', $contents, 'manage page must not use "忘记这个词" copy.');
    }

    public function test_manage_page_does_not_contain_affirmative_delete_review_history_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        // "不会删除复习历史" is safety copy (negated) — allowed.
        // Affirmative "删除复习历史" as a standalone action is forbidden.
        $blocked = [
            '将删除复习历史',
            '确认删除复习历史',
            '确认清除复习历史',
        ];
        foreach ($blocked as $copy) {
            $this->assertStringNotContainsString($copy, $contents, 'manage page must not use affirmative "删除复习历史" copy [' . $copy . '].');
        }
    }

    public function test_manage_page_does_not_contain_forget_as_user_copy(): void
    {
        $contents = file_get_contents($this->managePath);
        // Check that "Forget" does NOT appear as a visible user-facing action
        // (not as part of a word like "Forget-me-not" or in code comments)
        $this->assertStringNotContainsString('>忘记<', $contents, 'manage page must not have "忘记" as a button or action.');
    }
}
