<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * OpenCode-ReviewCardManageDangerCopy-1
 *
 * Frontend / UI guard tests for ReviewCardManage safety boundaries and copy.
 *
 * The project currently has no dedicated Vue component test runner, so these
 * are PHP source-string guards that scan the container and mutation owners.
 * This is a documented limitation.
 */
class ReviewCardManageUiGuardTest extends TestCase
{
    private string $managePath;
    private string $schedulingPath;
    private string $lifecyclePath;
    private string $deletePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->managePath = resource_path('js/components/ReviewCards/ReviewCardManage.vue');
        $this->schedulingPath = resource_path('js/components/ReviewCards/ReviewCardSchedulingMutationSurface.vue');
        $this->lifecyclePath = resource_path('js/components/ReviewCards/ReviewCardLifecycleMutationSurface.vue');
        $this->deletePath = resource_path('js/components/ReviewCards/ReviewCardDeleteMutationSurface.vue');
    }

    private function readManagementSafetySources(): string
    {
        return file_get_contents($this->managePath)
            . "\n" . file_get_contents($this->schedulingPath)
            . "\n" . file_get_contents($this->lifecyclePath)
            . "\n" . file_get_contents($this->deletePath);
    }

    public function test_manage_page_file_exists(): void
    {
        $this->assertFileExists($this->managePath, 'ReviewCardManage.vue must exist.');
        $this->assertFileExists($this->schedulingPath, 'ReviewCardSchedulingMutationSurface.vue must exist.');
        $this->assertFileExists($this->lifecyclePath, 'ReviewCardLifecycleMutationSurface.vue must exist.');
        $this->assertFileExists($this->deletePath, 'ReviewCardDeleteMutationSurface.vue must exist.');
    }

    // ==================== Phase 3D container closure ====================

    public function test_parent_no_longer_contains_legacy_enabled_client(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringNotContainsString('/enabled', $contents, 'container must not retain the legacy enabled endpoint client.');
        $this->assertStringNotContainsString('toggleEnabled', $contents, 'container must not retain the legacy enabled toggle method.');
    }

    public function test_parent_no_longer_contains_legacy_archive_restore_dialog_state(): void
    {
        $contents = file_get_contents($this->managePath);
        foreach (['archiveDialog', 'archiveTarget', 'restoreDialog', 'restoreTarget'] as $legacyState) {
            $this->assertStringNotContainsString($legacyState, $contents, 'container must not retain legacy dialog state [' . $legacyState . '].');
        }
    }

    public function test_lifecycle_owner_remains_registered_after_container_closure(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('<review-card-lifecycle-mutation-surface', $contents);
        $this->assertStringContainsString('ReviewCardLifecycleMutationSurface', $contents);
    }

    // ==================== Due now dialog ====================

    public function test_due_now_dialog_contains_not_review_rating_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('不是一次复习评分', $contents, 'due now dialog must state this is not a review rating.');
    }

    public function test_due_now_dialog_contains_not_write_review_history_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('不会写入复习历史', $contents, 'due now dialog must state no review history write.');
    }

    // ==================== Reset dialog ====================

    public function test_reset_dialog_contains_new_card_state_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('新卡状态', $contents, 'reset dialog must mention new card state.');
    }

    public function test_reset_dialog_contains_old_history_preserved_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('旧复习历史会保留', $contents, 'reset dialog must state old review logs are preserved.');
    }

    public function test_reset_dialog_contains_reset_record_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('reset', $contents, 'reset dialog must mention the reset record.');
    }

    // ==================== Delete dialog ====================

    public function test_delete_dialog_contains_review_history_preserved_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('复习历史会保留', $contents, 'delete dialog must state review history is preserved.');
    }

    public function test_delete_dialog_contains_source_records_preserved_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('阅读来源记录会保留', $contents, 'delete dialog must state source records are preserved.');
    }

    public function test_delete_dialog_contains_last_sense_explanation(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('最后一个已确认词义', $contents, 'delete dialog must mention last confirmed sense condition.');
    }

    // ==================== Bulk archive dialog ====================

    public function test_bulk_archive_dialog_contains_only_selected_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('当前勾选', $contents, 'bulk archive dialog must state only selected cards.');
    }

    // ==================== Bulk delete dialog ====================

    public function test_bulk_delete_dialog_contains_not_filter_scope_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringContainsString('不会按筛选条件全量删除', $contents, 'bulk delete dialog must state no filter-scope operation.');
    }

    // ==================== Forbidden copy ====================

    public function test_manage_page_does_not_contain_forget_copy(): void
    {
        $contents = $this->readManagementSafetySources();
        $this->assertStringNotContainsString('忘记这个词', $contents, 'manage page must not use "忘记这个词" copy.');
    }

    public function test_manage_page_does_not_contain_affirmative_delete_review_history_copy(): void
    {
        $contents = $this->readManagementSafetySources();
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
        $contents = $this->readManagementSafetySources();
        // Check that "Forget" does NOT appear as a visible user-facing action
        // (not as part of a word like "Forget-me-not" or in code comments)
        $this->assertStringNotContainsString('>忘记<', $contents, 'manage page must not have "忘记" as a button or action.');
    }
}
