<?php

namespace Tests\Feature;

use Tests\TestCase;

class M11ManualOperationUiGuardTest extends TestCase
{
    public function test_advanced_browser_keeps_server_preview_and_apply_out_of_ordinary_review(): void
    {
        $scheduling = file_get_contents(resource_path(
            'js/components/ReviewCards/ReviewCardSchedulingMutationSurface.vue',
        ));
        $manage = file_get_contents(resource_path(
            'js/components/ReviewCards/ReviewCardManage.vue',
        ));
        $reviewer = file_get_contents(resource_path(
            'js/components/Senses/SenseReview.vue',
        ));

        $this->assertStringContainsString('/manual-operations/preview', $scheduling);
        $this->assertStringContainsString('/manual-operations/apply', $scheduling);
        $this->assertStringContainsString('expected_state_fingerprint', $scheduling);
        $this->assertStringContainsString('projected_after_state', $scheduling);
        $this->assertStringContainsString('previewLoading', $scheduling);
        $this->assertStringContainsString('previewError', $scheduling);
        $this->assertStringContainsString('resetCounts', $scheduling);
        $this->assertStringContainsString('type="date"', $scheduling);
        $this->assertStringContainsString('@set-due="confirmSetDue"', $manage);
        $this->assertStringNotContainsString('ReviewCardSchedulingMutationSurface', $reviewer);
        $this->assertStringNotContainsString('openSetDueDialog', $reviewer);
        $this->assertStringNotContainsString('/manual-operations/preview', $reviewer);
        $this->assertStringNotContainsString('/manual-operations/apply', $reviewer);
        $this->assertStringNotContainsString(
            'axios.post(`/review-cards/manage/${this.currentCard.review_card_id}/reset`)',
            $reviewer,
        );
    }

    public function test_single_card_lifecycle_actions_join_manual_ledger(): void
    {
        $browserLifecycle = file_get_contents(resource_path(
            'js/components/ReviewCards/ReviewCardLifecycleMutationSurface.vue',
        ));
        $reviewer = file_get_contents(resource_path(
            'js/components/Senses/SenseReview.vue',
        ));

        foreach (['bury_next_day', "suspend: 'suspend'", "resume: 'resume'"] as $needle) {
            $this->assertStringContainsString($needle, $browserLifecycle);
            $this->assertStringNotContainsString($needle, $reviewer);
        }
        $this->assertStringContainsString('/manual-operations/preview', $browserLifecycle);
        $this->assertStringContainsString('/manual-operations/apply', $browserLifecycle);
    }

    public function test_card_info_exposes_manual_history_and_transitions(): void
    {
        $drawer = file_get_contents(resource_path(
            'js/components/ReviewCards/ReviewCardInfoDrawer.vue',
        ));

        $this->assertStringContainsString('Manual operations', $drawer);
        $this->assertStringContainsString('operation.operation_id', $drawer);
        $this->assertStringContainsString('operation.source_device_uuid', $drawer);
        $this->assertStringContainsString('operation.before_state', $drawer);
        $this->assertStringContainsString('operation.after_state', $drawer);
        $this->assertStringContainsString('operation.can_undo', $drawer);
        $this->assertStringContainsString('operation.can_redo', $drawer);
        $this->assertStringContainsString('/review-card-operations/', $drawer);
        $this->assertStringContainsString('manualOperationError', $drawer);
    }
}
