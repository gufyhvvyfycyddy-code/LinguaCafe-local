<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegacyEntryUiGuardTest extends TestCase
{
    public function test_text_lookup_components_do_not_expose_legacy_entry_copy(): void
    {
        $componentPaths = [
            resource_path('js/components/Text/WordSensesList.vue'),
            resource_path('js/components/Text/VocabularySideBox.vue'),
            resource_path('js/components/Text/VocabularyBox.vue'),
        ];

        $blockedCopy = [
            '旧词条释义',
            '旧版释义',
            '旧版示意',
            'legacy word review',
        ];

        foreach ($componentPaths as $path) {
            $contents = file_get_contents($path);

            foreach ($blockedCopy as $copy) {
                $this->assertStringNotContainsString($copy, $contents, $path . ' should not expose legacy entry copy.');
            }
        }
    }
}
