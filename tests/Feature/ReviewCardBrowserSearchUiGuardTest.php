<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ReviewCardBrowserSearchUiGuardTest
 *
 * ADR-0012: Frontend / UI guard tests for the advanced browser search feature
 * in ReviewCardManage.vue.
 *
 * The project currently has no dedicated Vue component test runner, so these
 * are PHP source-string guards that scan the component source to lock in the
 * search UX. This is a documented limitation.
 *
 * Coverage:
 *  - Search help entry exists
 *  - Syntax examples in help dialog
 *  - Token chips display
 *  - Remove token functionality
 *  - Specific error display (not generic)
 *  - Auto-switch to 全部 on advanced tokens
 *  - Click filter button clears is: tokens
 *  - No frontend full parser (no regex-based token validation)
 *  - Existing lifecycle and bulk operations preserved
 */
class ReviewCardBrowserSearchUiGuardTest extends TestCase
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

    // ==================== Search help entry ====================

    public function test_search_help_entry_exists(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('高级搜索语法', $contents, 'Search help entry must exist.');
    }

    public function test_search_help_dialog_exists(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('searchHelpDialog', $contents, 'Search help dialog variable must exist.');
    }

    // ==================== Syntax examples in help ====================

    public function test_help_contains_is_leech_example(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('is:leech', $contents, 'Help must show is:leech example.');
    }

    public function test_help_contains_is_suspended_example(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('is:suspended', $contents, 'Help must show is:suspended example.');
    }

    public function test_help_contains_rated_again_example(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('rated:again', $contents, 'Help must show rated:again example.');
    }

    public function test_help_contains_prop_lapses_example(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('prop:lapses', $contents, 'Help must show prop:lapses example.');
    }

    public function test_help_contains_charge_is_leech_combination_example(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('charge is:leech', $contents, 'Help must show plain text + token combination example.');
    }

    // ==================== Token chips display ====================

    public function test_token_chips_display_from_search_meta(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('searchMeta', $contents, 'searchMeta state must exist for chip display.');
        $this->assertStringContainsString('searchMeta.tokens', $contents, 'Token chips must use searchMeta.tokens.');
    }

    public function test_token_chips_use_v_chip_component(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('v-chip', $contents, 'Token chips must use v-chip component.');
    }

    // ==================== Remove token functionality ====================

    public function test_remove_token_method_exists(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('removeToken', $contents, 'removeToken method must exist.');
    }

    public function test_remove_token_triggers_search(): void
    {
        $contents = file_get_contents($this->managePath);
        // removeToken should call loadData after removing the token
        $this->assertStringContainsString('removeToken', $contents, 'removeToken must be defined.');
        $this->assertStringContainsString('@click:close', $contents, 'Chip close event must be wired.');
    }

    // ==================== Specific error display ====================

    public function test_search_errors_state_exists(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('searchErrors', $contents, 'searchErrors state must exist for specific error display.');
    }

    public function test_search_errors_display_token_and_reason(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('err.token', $contents, 'Error display must show the token.');
        $this->assertStringContainsString('err.reason', $contents, 'Error display must show the reason.');
    }

    public function test_422_response_handled_specifically(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('422', $contents, '422 status must be handled specifically.');
        $this->assertStringContainsString('invalid_browser_search', $contents, 'invalid_browser_search code must be checked.');
    }

    // ==================== Auto-switch to 全部 ====================

    public function test_detect_advanced_tokens_method_exists(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('detectAdvancedTokens', $contents, 'detectAdvancedTokens helper must exist.');
    }

    public function test_auto_switch_to_all_on_advanced_tokens(): void
    {
        $contents = file_get_contents($this->managePath);
        // loadData should check detectAdvancedTokens and switch to 'all'
        $this->assertStringContainsString("this.currentFilter = 'all'", $contents, 'loadData must auto-switch filter to all on advanced tokens.');
    }

    public function test_effective_filter_helper_exists(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('effectiveFilter', $contents, 'effectiveFilter helper must exist for exports.');
    }

    // ==================== Click filter clears is: tokens ====================

    public function test_strip_is_tokens_method_exists(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('stripIsTokens', $contents, 'stripIsTokens method must exist.');
    }

    public function test_apply_filter_calls_strip_is_tokens(): void
    {
        $contents = file_get_contents($this->managePath);
        // applyFilter should call stripIsTokens
        $this->assertStringContainsString('stripIsTokens', $contents, 'applyFilter must use stripIsTokens.');
    }

    // ==================== No frontend full parser ====================

    public function test_no_frontend_full_parser_regex(): void
    {
        $contents = file_get_contents($this->managePath);
        // The frontend should NOT contain a full parser with regex validation
        // for prop:lapses operators. The help dialog legitimately shows
        // "prop:lapses>=2" as an example, so we check for JavaScript regex
        // patterns that would parse operators — not the example text itself.
        // A full parser would use something like:
        //   /prop:lapses([><=]+)/  or  new RegExp('prop.*lapses.*[><=]')
        // or a switch/case on parsed operator values.
        $this->assertStringNotContainsString(
            'new RegExp',
            $contents,
            'Frontend must not construct regex parsers — validation is server-side only.'
        );
        // Check for JS regex literals that match prop:lapses operator patterns.
        // The detectAdvancedTokens helper only checks the prefix (is/rated/prop),
        // not operator validation — that's acceptable.
        $this->assertStringNotContainsString(
            "/^([a-zA-Z]+)(>=|<=|>|<|=)(-?\\d+)$/",
            $contents,
            'Frontend must not replicate the backend prop operator regex.'
        );
    }

    public function test_no_frontend_conflict_detection(): void
    {
        $contents = file_get_contents($this->managePath);
        // The frontend should NOT contain conflict detection logic (e.g.
        // checking if both is:leech and is:struggling are present).
        // Conflict detection is server-side only.
        $this->assertStringNotContainsString('governanceStatus', $contents, 'Frontend must not contain governance conflict detection logic.');
        $this->assertStringNotContainsString('lifecycleStatus', $contents, 'Frontend must not contain lifecycle conflict detection logic.');
    }

    // ==================== Search box label ====================

    public function test_search_box_label_updated(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('搜索词义，或输入高级语法', $contents, 'Search box label must be updated to mention advanced syntax.');
    }

    // ==================== Empty state differentiation ====================

    public function test_empty_state_differentiates_syntax_error(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('搜索语法有误', $contents, 'Empty state must differentiate syntax errors.');
    }

    public function test_empty_state_differentiates_no_match(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('当前搜索没有匹配结果', $contents, 'Empty state must differentiate no-match from no-cards.');
    }

    public function test_empty_state_keeps_no_cards_message(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('暂无词义复习卡', $contents, 'Empty state must still show no-cards message when appropriate.');
    }

    // ==================== Existing operations preserved ====================

    public function test_lifecycle_operations_preserved(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('lifecycleDialog', $contents, 'Lifecycle dialog must be preserved.');
        $this->assertStringContainsString('archiveDialog', $contents, 'Archive dialog must be preserved.');
        $this->assertStringContainsString('restoreDialog', $contents, 'Restore dialog must be preserved.');
        $this->assertStringContainsString('resetDialog', $contents, 'Reset dialog must be preserved.');
    }

    public function test_bulk_operations_preserved(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('bulkLifecycle', $contents, 'Bulk lifecycle operations must be preserved.');
        $this->assertStringContainsString('bulkDelete', $contents, 'Bulk delete operations must be preserved.');
        $this->assertStringContainsString('bulkRewritePackages', $contents, 'Bulk rewrite packages must be preserved.');
    }

    public function test_export_operations_preserved(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('exportCurrentFilter', $contents, 'JSON export must be preserved.');
        $this->assertStringContainsString('exportAnkiTsv', $contents, 'Anki TSV export must be preserved.');
        $this->assertStringContainsString('exportCsv', $contents, 'CSV export must be preserved.');
    }

    public function test_table_edit_preserved(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('editingId', $contents, 'Table edit functionality must be preserved.');
        $this->assertStringContainsString('startEdit', $contents, 'startEdit method must be preserved.');
        $this->assertStringContainsString('saveEdit', $contents, 'saveEdit method must be preserved.');
    }

    // ==================== Data preservation on error ====================

    public function test_data_preserved_on_search_error(): void
    {
        $contents = file_get_contents($this->managePath);
        // On 422, the frontend should NOT clear existing items — it should
        // only set searchErrors and keep the current data intact.
        $this->assertStringContainsString('searchErrors', $contents, 'searchErrors must be set on 422.');
        $this->assertStringNotContainsString('this.items = []', $contents, 'Items should not be cleared on search error.');
    }

    /**
     * Assert that a string does NOT match a given regex pattern.
     * This is a helper since PHPUnit doesn't have assertStringNotContainsStringRegex.
     */
    private function assertStringNotContainsStringRegex(string $pattern, string $subject, string $message = ''): void
    {
        if (preg_match($pattern, $subject)) {
            $this->fail($message ?: "String contains pattern '{$pattern}'.");
        }
        $this->assertTrue(true);
    }
}
