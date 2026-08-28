<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeFixtureLifecycleContractTest extends TestCase
{
    public function test_h02_uses_one_testing_fixture_owner_across_host_and_container_runtimes(): void
    {
        $runner = $this->source('tests/Support/run-h02-representative-runtime.php');
        $support = $this->source('tests/Support/H02RepresentativeFixtureSupport.php');
        $container = $this->source('tests/Support/run-h02-container-runtime.php');
        $pab = $this->source('tests/Support/run-pab-r3-browser-acceptance.php');
        $userModel = $this->source('app/Models/User.php');
        $wordSenseService = $this->source('app/Services/WordSenseService.php');
        $reviewCardService = $this->source('app/Services/ReviewCardService.php');

        self::assertStringContainsString("require_once __DIR__.'/H02RepresentativeFixtureSupport.php';", $runner);
        self::assertStringContainsString("require_once __DIR__.'/H02RepresentativeFixtureSupport.php';", $container);
        self::assertStringContainsString('H02RepresentativeFixtureSupport::prepareRows($vus)', $runner);
        self::assertStringContainsString('H02RepresentativeFixtureSupport::provision($fixtureRows)', $runner);
        self::assertStringContainsString('H02RepresentativeFixtureSupport::cleanup($fixtureState)', $runner);
        self::assertStringContainsString('H02RepresentativeFixtureSupport::prepareRows($vus)', $container);
        self::assertStringContainsString('H02RepresentativeFixtureSupport::provision($rows)', $container);

        self::assertStringContainsString("'email' => \"h02-vu-{\$suffix}@example.test\"", $support);
        self::assertStringContainsString("'password' => \"H02-testing-{\$suffix}!\"", $support);
        self::assertStringContainsString("'language' => 'en'", $support);
        self::assertStringContainsString('User::forceCreate(', $support);
        self::assertStringNotContainsString('User::factory()', $support);
        self::assertStringContainsString("'password' => \$row['password']", $support);
        self::assertStringContainsString("'selected_language' => \$row['language']", $support);
        self::assertStringContainsString("'is_admin' => false", $support);
        self::assertStringContainsString("'password' => 'hashed'", $userModel);

        self::assertStringContainsString('Book::forceCreate(', $support);
        self::assertStringContainsString('Chapter::forceCreate(', $support);
        self::assertStringContainsString("'processing_status' => 'processed'", $support);
        self::assertStringContainsString('public function createSense(array $data): WordSense', $wordSenseService);
        self::assertStringContainsString('app(WordSenseService::class)->createSense(', $support);
        self::assertStringContainsString('WordSense::STATUS_CONFIRMED', $support);
        self::assertStringContainsString('public function ensureSenseCard(WordSense $sense): ?ReviewCard', $reviewCardService);
        self::assertStringContainsString('app(ReviewCardService::class)->ensureSenseCard(', $support);
        self::assertStringContainsString("'review_card_id' => \$reviewCard->id", $support);

        foreach (['ReviewLog::create', 'recordReview(', 'FsrsSchedulingService', 'schedule(', 'Artisan::call(', 'migrate:'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $support);
        }

        foreach ([
            'ReviewLog::whereIn(',
            'ReviewCard::whereIn(',
            'WordSense::whereIn(',
            'Chapter::whereIn(',
            'Book::whereIn(',
            'User::whereIn(',
            'H02_FIXTURE_CLEANUP_UNPROVEN',
        ] as $expected) {
            self::assertStringContainsString($expected, $support);
        }
        self::assertStringNotContainsString('User::query()->delete()', $support);
        self::assertStringNotContainsString('ReviewCard::query()->delete()', $support);

        self::assertStringContainsString('H02_CONTAINER_ENV_NOT_TESTING', $container);
        self::assertStringContainsString('H02_CONTAINER_DATABASE_NOT_TESTING', $container);
        self::assertStringContainsString('DB::connection()->getDatabaseName()', $container);
        self::assertStringContainsString('function h02ContainerVerify(int $vus, int $expectedRated): array', $container);
        self::assertStringContainsString("require_once __DIR__.'/H01ObservabilitySampleSupport.php';", $container);
        self::assertStringContainsString('H01ObservabilitySampleSupport::collect($runtime[\'queue_connection\'], $runtime[\'queue_name\'])', $container);
        self::assertStringContainsString('ReviewLog::SOURCE_SENSE_REVIEW', $container);
        self::assertStringContainsString("'duplicate_log_cards'", $container);
        self::assertStringContainsString("'invalid_fsrs_cards'", $container);
        self::assertStringNotContainsString('docker compose', strtolower($container));
        self::assertStringNotContainsString('docker exec', strtolower($container));

        self::assertStringContainsString('runPabR3BrowserAcceptanceCli(', $runner);
        self::assertStringContainsString('H01LoadObservabilityHarness::buildFinalSummary(', $runner);
        self::assertStringContainsString('H01LoadObservabilityHarness::assertRuntimeBoundary', $runner);

        $childCall = strpos($pab, '$childExitCode = ($this->runChild)($command, $childEnvironment);');
        $sentinelCleanup = strpos($pab, "'event' => 'sentinel_cleanup'");
        $leaseRelease = strpos($pab, '$lease->release();');
        self::assertIsInt($childCall);
        self::assertIsInt($sentinelCleanup);
        self::assertIsInt($leaseRelease);
        self::assertLessThan($sentinelCleanup, $childCall);
        self::assertLessThan($leaseRelease, $sentinelCleanup);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
