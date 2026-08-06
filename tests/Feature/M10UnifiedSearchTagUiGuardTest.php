<?php

namespace Tests\Feature;

use Tests\TestCase;

class M10UnifiedSearchTagUiGuardTest extends TestCase
{
    public function test_tag_mutations_refresh_the_card_table(): void
    {
        $manager = file_get_contents(resource_path(
            'js/components/ReviewCards/WordSenseTagManager.vue'
        ));
        $searchSurface = file_get_contents(resource_path(
            'js/components/ReviewCards/ReviewCardSearchSurface.vue'
        ));
        $manage = file_get_contents(resource_path(
            'js/components/ReviewCards/ReviewCardManage.vue'
        ));

        $this->assertSame(2, substr_count($manager, "\$emit('catalog-mutated')"));
        $this->assertStringContainsString(
            '@catalog-mutated="$emit(\'tag-catalog-mutated\')"',
            $searchSurface,
        );
        $this->assertStringContainsString(
            '@tag-catalog-mutated="loadData"',
            $manage,
        );
    }

    public function test_tag_surfaces_keep_loading_empty_and_error_states(): void
    {
        $manager = file_get_contents(resource_path(
            'js/components/ReviewCards/WordSenseTagManager.vue'
        ));
        $bulkPicker = file_get_contents(resource_path(
            'js/components/ReviewCards/WordSenseTagBulkPicker.vue'
        ));

        $this->assertStringContainsString('还没有标签。', $manager);
        $this->assertStringContainsString(':loading="loading"', $manager);
        $this->assertStringContainsString(':hide-details="!loadError"', $manager);
        $this->assertStringContainsString(
            ':error-messages="loadError ? [loadError] : []"',
            $manager,
        );
        $this->assertStringContainsString('批量标签操作失败。', $bulkPicker);
    }
}
