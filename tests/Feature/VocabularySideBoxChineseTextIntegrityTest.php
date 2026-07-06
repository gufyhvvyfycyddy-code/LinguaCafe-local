<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GM52-AIStudyCardV5-SideBoxMojibakeRegressionFix-1000-3
 *
 * Chinese text integrity guard for VocabularySideBox.vue.
 *
 * Previous round (GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2)
 * introduced widespread user-visible Chinese mojibake in VocabularySideBox.vue
 * while extracting the V1-V5 workflow into AiStudyCardDesktopWorkflow.vue.
 * The mojibake included PUA (Private Use Area) characters that broke user
 * labels, titles, placeholders, button text, and HTML comments. This guard
 * locks the restored Chinese text so the same regression cannot reappear.
 *
 * Covers:
 *  1. VocabularySideBox.vue exists.
 *  2. Contains all required correct Chinese text fragments (21 items).
 *  3. Does NOT contain known mojibake fragments (11 patterns).
 *  4. Still mounts <AiStudyCardDesktopWorkflow> (feature island preserved).
 *  5. Does NOT contain old duplicated V1-V5 workflow fingerprints
 *     (5 patterns) — ensures we did not roll back the feature island.
 */
class VocabularySideBoxChineseTextIntegrityTest extends TestCase
{
    private string $sideBoxPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sideBoxPath = resource_path('js/components/Text/VocabularySideBox.vue');
    }

    /**
     * 1. VocabularySideBox.vue must exist.
     */
    public function test_side_box_file_exists(): void
    {
        $this->assertFileExists($this->sideBoxPath, 'VocabularySideBox.vue must exist.');
    }

    /**
     * 2. VocabularySideBox.vue must contain all required correct Chinese text.
     *    These are the user-visible labels / titles / placeholders / button
     *    text / comments that were broken by mojibake in the previous round.
     */
    public function test_side_box_contains_required_chinese_text(): void
    {
        $contents = file_get_contents($this->sideBoxPath);

        $required = [
            '请选择一个单词或短语',
            '新短语',
            '单词',
            '短语',
            '显示变形',
            '朗读',
            '发送到 Anki',
            '返回单词',
            '取消选择',
            '单词基础信息',
            '[修改]',
            '发音',
            'FSRS 熟悉度',
            '尚未复习',
            '词元读音',
            '读音',
            '忽略',
            '标为已知',
            '回归为新词',
            '添加新释义',
            '候选结果（AI + 词典）',
        ];

        foreach ($required as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $contents,
                "VocabularySideBox.vue must contain correct Chinese text: {$fragment}"
            );
        }
    }

    /**
     * 3. VocabularySideBox.vue must NOT contain known mojibake fragments.
     *    These patterns appeared when UTF-8 bytes were mis-decoded as GBK/CP936.
     *    Some include PUA (Private Use Area) characters. If any of these appear
     *    again, the file has been re-corrupted.
     */
    public function test_side_box_does_not_contain_mojibake_fragments(): void
    {
        $contents = file_get_contents($this->sideBoxPath);

        $forbidden = [
            '璇烽',
            '鍗曡瘝',
            '鐭',
            '鏂扮',
            '淇',
            '鏈楄',
            '鍙戦',
            '杩斿',
            'FSRS 鐔',
            '?/span',
            '?/v-btn',
            '?/v-chip',
        ];

        foreach ($forbidden as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                $contents,
                "VocabularySideBox.vue must NOT contain mojibake fragment: {$fragment}"
            );
        }
    }

    /**
     * 4. VocabularySideBox.vue must still mount <AiStudyCardDesktopWorkflow>.
     *    This proves the feature island architecture from
     *    GM52-AIStudyCardV5-DesktopWorkflowFeatureIsland-1000-2 was preserved
     *    and this round only restored Chinese text.
     */
    public function test_side_box_still_mounts_feature_island(): void
    {
        $contents = file_get_contents($this->sideBoxPath);

        $this->assertStringContainsString(
            "import AiStudyCardDesktopWorkflow from './AiStudyCardDesktopWorkflow.vue'",
            $contents,
            'VocabularySideBox.vue must still import AiStudyCardDesktopWorkflow (feature island preserved).'
        );
        $this->assertStringContainsString(
            'AiStudyCardDesktopWorkflow,',
            $contents,
            'VocabularySideBox.vue must still register AiStudyCardDesktopWorkflow in components.'
        );
        $this->assertStringContainsString(
            '<AiStudyCardDesktopWorkflow',
            $contents,
            'VocabularySideBox.vue must still render <AiStudyCardDesktopWorkflow> in template.'
        );
    }

    /**
     * 5. VocabularySideBox.vue must NOT contain old duplicated V1-V5 workflow
     *    fingerprints. This ensures we did not roll back the feature island
     *    convergence when restoring Chinese text.
     */
    public function test_side_box_does_not_contain_old_workflow_fingerprints(): void
    {
        $contents = file_get_contents($this->sideBoxPath);

        $forbidden = [
            '<v-dialog v-model="aiPendingListDialog"',
            '<v-dialog v-model="aiStudyCardPreviewDialog"',
            'aiPreviewPackage: null',
            'parseAiRecommendations()',
            'generateFinalCandidatesPackage()',
        ];

        foreach ($forbidden as $fingerprint) {
            $this->assertStringNotContainsString(
                $fingerprint,
                $contents,
                "VocabularySideBox.vue must NOT contain old V1-V5 workflow fingerprint: {$fingerprint} — use <AiStudyCardDesktopWorkflow> instead."
            );
        }
    }
}
