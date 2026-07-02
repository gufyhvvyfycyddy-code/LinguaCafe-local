<?php

namespace Tests\Feature;

use Tests\TestCase;

class MorphologyMatrixUiGuardTest extends TestCase
{
    public function test_word_senses_list_keeps_surface_lemma_display_and_lemma_preferred_add_sense_payload(): void
    {
        $contents = file_get_contents(resource_path('js/components/Text/WordSensesList.vue'));

        $this->assertStringContainsString('当前词形', $contents);
        $this->assertStringContainsString('词元：', $contents);
        $this->assertStringContainsString('surfaceWord()', $contents);
        $this->assertStringContainsString('effectiveLemma()', $contents);
        $this->assertStringContainsString('studyBase || this.baseWord || this.lemma || this.surface || this.word', $contents);
        $this->assertStringContainsString('lemma: this.effectiveLemma', $contents);
        $this->assertStringContainsString('surface_form: this.surfaceWord', $contents);
        $this->assertStringContainsString('已学词义候选', $contents);
        $this->assertStringContainsString('未调用 AI 判断', $contents);
    }

    public function test_text_lookup_components_still_hide_legacy_entry_copy(): void
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
