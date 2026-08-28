<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H03FlowLatencyInstrumentationContractTest extends TestCase
{
    private const WORKLOAD_PATH = __DIR__.'/../load/h02-representative-workloads.js';

    public function test_representative_workload_emits_one_latency_trend_per_request_type(): void
    {
        $source = (string) file_get_contents(self::WORKLOAD_PATH);

        $this->assertStringContainsString("import { Trend } from 'k6/metrics';", $source);

        foreach ([
            'h03_login_page_duration' => 'loginPageDuration.add(loginPage.timings.duration);',
            'h03_login_post_duration' => 'loginPostDuration.add(loginResponse.timings.duration);',
            'h03_reading_duration' => 'readingDuration.add(response.timings.duration);',
            'h03_lookup_duration' => 'lookupDuration.add(response.timings.duration);',
            'h03_sense_review_duration' => 'senseReviewDuration.add(response.timings.duration);',
        ] as $metric => $sampleCall) {
            $this->assertStringContainsString("new Trend('{$metric}', true)", $source);
            $this->assertStringContainsString($sampleCall, $source);
        }
    }

    public function test_h03_instrumentation_does_not_create_a_second_summary_schema_or_endpoint(): void
    {
        $source = (string) file_get_contents(self::WORKLOAD_PATH);

        $this->assertSame(1, substr_count($source, 'export function handleSummary(data)'));
        $this->assertStringContainsString('[summaryPath]: JSON.stringify(data, null, 2)', $source);
        $this->assertStringNotContainsString('schema_version', $source);
        $this->assertStringNotContainsString('/__testing/', $source);
    }
}
