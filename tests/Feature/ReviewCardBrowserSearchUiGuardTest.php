<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ADR-0012 frontend guards for the Browser search surface.
 *
 * There is no dedicated Vue component runner in this project, so this test
 * reads both the page container and the extracted search component. The
 * backend remains the only search-language parser and validator.
 */
class ReviewCardBrowserSearchUiGuardTest extends TestCase
{
    private string $managePath;
    private string $searchSurfacePath;
    private string $tableSurfacePath;
    private string $schedulingSurfacePath;
    private string $lifecycleSurfacePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->managePath = resource_path('js/components/ReviewCards/ReviewCardManage.vue');
        $this->searchSurfacePath = resource_path('js/components/ReviewCards/ReviewCardSearchSurface.vue');
        $this->tableSurfacePath = resource_path('js/components/ReviewCards/ReviewCardTableSurface.vue');
        $this->schedulingSurfacePath = resource_path('js/components/ReviewCards/ReviewCardSchedulingMutationSurface.vue');
        $this->lifecycleSurfacePath = resource_path('js/components/ReviewCards/ReviewCardLifecycleMutationSurface.vue');
    }

    private function browserContents(): string
    {
        return file_get_contents($this->managePath)
            . "\n"
            . file_get_contents($this->searchSurfacePath)
            . "\n"
            . file_get_contents($this->tableSurfacePath)
            . "\n"
            . file_get_contents($this->schedulingSurfacePath)
            . "\n"
            . file_get_contents($this->lifecycleSurfacePath);
    }

    public function test_browser_source_files_exist(): void
    {
        $this->assertFileExists($this->managePath);
        $this->assertFileExists($this->searchSurfacePath);
        $this->assertFileExists($this->tableSurfacePath);
        $this->assertFileExists($this->schedulingSurfacePath);
        $this->assertFileExists($this->lifecycleSurfacePath);
    }

    public function test_search_help_and_examples_exist(): void
    {
        $contents = $this->browserContents();
        $this->assertStringContainsString('高级搜索语法', $contents);
        $this->assertStringContainsString('searchHelpDialog', $contents);
        $this->assertStringContainsString('is:leech', $contents);
        $this->assertStringContainsString('is:suspended', $contents);
        $this->assertStringContainsString('rated:again', $contents);
        $this->assertStringContainsString('prop:lapses', $contents);
        $this->assertStringContainsString('charge is:leech', $contents);
    }

    public function test_server_tokens_and_specific_errors_are_presented(): void
    {
        $contents = $this->browserContents();
        $this->assertStringContainsString('searchMeta.tokens', $contents);
        $this->assertStringContainsString('@click:close="removeToken(token)"', $contents);
        $this->assertStringContainsString('err.token', $contents);
        $this->assertStringContainsString('err.reason', $contents);
        $this->assertStringContainsString('invalid_browser_search', $contents);
        $this->assertStringContainsString('422', $contents);
    }

    public function test_search_surface_owns_simple_input_helpers(): void
    {
        $surface = file_get_contents($this->searchSurfacePath);
        $this->assertStringContainsString('detectAdvancedTokens(query)', $surface);
        $this->assertStringContainsString("this.currentFilter = 'all'", $surface);
        $this->assertStringContainsString('stripIsTokens(query)', $surface);
        $this->assertStringContainsString('removeToken(token)', $surface);
        $this->assertStringContainsString("this.\$emit('apply', this.currentFilterState)", $surface);
    }

    public function test_frontend_does_not_reimplement_search_parser(): void
    {
        $contents = $this->browserContents();
        $this->assertStringNotContainsString('new RegExp', $contents);
        $this->assertStringNotContainsString(
            "/^([a-zA-Z]+)(>=|<=|>|<|=)(-?\\d+)$/",
            $contents
        );
        $this->assertStringNotContainsString('governanceStatus', $contents);
        $this->assertStringNotContainsString('lifecycleStatus', $contents);
    }

    public function test_search_copy_and_empty_states_are_preserved(): void
    {
        $contents = $this->browserContents();
        $this->assertStringContainsString('搜索词义，或输入高级语法', $contents);
        $this->assertStringContainsString('搜索语法有误', $contents);
        $this->assertStringContainsString('当前搜索没有匹配结果', $contents);
        $this->assertStringContainsString('暂无词义复习卡', $contents);
    }

    public function test_existing_page_operations_are_preserved(): void
    {
        $contents = $this->browserContents();
        $manageContents = file_get_contents($this->managePath);
        $lifecycleContents = file_get_contents($this->lifecycleSurfacePath);
        $this->assertStringContainsString('lifecycleDialog', $lifecycleContents);
        $this->assertStringNotContainsString('lifecycleDialog:', $manageContents);
        $this->assertStringContainsString('archiveDialog', $manageContents);
        $this->assertStringContainsString('restoreDialog', $manageContents);
        $this->assertStringContainsString('resetDialog', file_get_contents($this->schedulingSurfacePath));
        $this->assertStringNotContainsString('resetDialog:', $manageContents);
        $this->assertStringContainsString('bulkLifecycle', $lifecycleContents);
        $this->assertStringContainsString('confirmBulkLifecycle', $manageContents);
        $this->assertStringContainsString('bulkDelete', $manageContents);
        $this->assertStringContainsString('bulkRewritePackages', $manageContents);
        $this->assertStringContainsString('exportCurrentFilter', $contents);
        $this->assertStringContainsString('exportAnkiTsv', $contents);
        $this->assertStringContainsString('exportCsv', $contents);
        $this->assertStringContainsString('editingId', $contents);
        $this->assertStringContainsString('startEdit', $manageContents);
        $this->assertStringContainsString('saveEdit', $manageContents);
        $this->assertStringNotContainsString("axios.post('/review-cards/manage/bulk", file_get_contents($this->tableSurfacePath));
    }

    public function test_invalid_search_preserves_existing_table_data(): void
    {
        $contents = file_get_contents($this->managePath);
        $this->assertStringContainsString('searchErrors', $contents);
        $this->assertStringNotContainsString('this.items = []', $contents);
    }
}
