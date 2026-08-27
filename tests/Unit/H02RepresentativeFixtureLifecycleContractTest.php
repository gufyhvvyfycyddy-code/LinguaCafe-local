<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeFixtureLifecycleContractTest extends TestCase
{
    public function test_h02_runner_owns_the_real_testing_fixture_lifecycle(): void
    {
        $runner = $this->source('tests/Support/run-h02-representative-runtime.php');
        $pab = $this->source('tests/Support/run-pab-r3-browser-acceptance.php');
        $userFactory = $this->source('database/factories/UserFactory.php');
        $userModel = $this->source('app/Models/User.php');
        $wordSenseService = $this->source('app/Services/WordSenseService.php');
        $reviewCardService = $this->source('app/Services/ReviewCardService.php');

        // The current H-02 boundary is a temporary manifest plus a child
        // process. The manifest is input data, not proof of database rows.
        self::assertStringContainsString('function h02PrepareFixtureRows(int $vus): array', $runner);
        self::assertStringContainsString('does not claim that these records have been inserted', $runner);
        self::assertStringContainsString('function h02CreateFixtureManifest(array $fixtureRows): string', $runner);
        self::assertStringContainsString('function h02ReadFixtureManifest(string $manifestPath, int $vus): array', $runner);
        self::assertStringContainsString('H02_FIXTURES_JSON', $runner);
        self::assertStringContainsString('h01StartProcess(', $runner);
        self::assertStringContainsString('h02CleanupFixtures($ownedManifestPath)', $runner);
        self::assertStringContainsString('H01LoadObservabilityHarness::assertRuntimeBoundary', $runner);
        self::assertStringContainsString('$application->environment(\'testing\')', $runner);
        self::assertStringContainsString('DB::connection()->getDatabaseName()', $runner);
        self::assertStringContainsString('\'email\' => "h02-vu-{$suffix}@example.test"', $runner);
        self::assertStringContainsString('@chmod($manifestPath, 0600)', $runner);

        // These are the legitimate current application owners. There is one
        // User factory, no fixture-specific production owner, and the sense
        // card must still enter through the existing service seam.
        self::assertStringContainsString("Hash::make('password')", $userFactory);
        self::assertStringContainsString("'password' => 'hashed'", $userModel);
        self::assertStringContainsString('public function createSense(array $data): WordSense', $wordSenseService);
        self::assertStringContainsString('public function ensureSenseCard(WordSense $sense): ?ReviewCard', $reviewCardService);

        // PAB owns the lease and sentinel. The H-02 child therefore has to
        // finish fixture cleanup before PAB performs either outer cleanup.
        $childCall = strpos($pab, '$childExitCode = ($this->runChild)($command, $childEnvironment);');
        $sentinelCleanup = strpos($pab, "'event' => 'sentinel_cleanup'");
        $leaseRelease = strpos($pab, '$lease->release();');
        self::assertIsInt($childCall);
        self::assertIsInt($sentinelCleanup);
        self::assertIsInt($leaseRelease);
        self::assertLessThan($sentinelCleanup, $childCall);
        self::assertLessThan($leaseRelease, $sentinelCleanup);

        // The real fixture lifecycle stays in this existing runner, not in a
        // seeder class, command, route, or second lease/sentinel owner.
        self::assertTrue(
            preg_match(
                '/function\s+h02ProvisionDatabaseFixtures\s*\(\s*array\s+\$fixtureRows\s*\)\s*:\s*array/',
                $runner,
            ) === 1,
            'H-02: the existing outer runner has no real testing-DB fixture provisioning seam before k6.',
        );

        $provision = $this->sectionBetween(
            $runner,
            'function h02ProvisionDatabaseFixtures(',
            'function h02CleanupDatabaseFixtures(',
        );
        $cleanup = $this->sectionBetween(
            $runner,
            'function h02CleanupDatabaseFixtures(',
            'function h02DurationMilliseconds(',
        );
        $measurement = $this->sectionBetween(
            $runner,
            'function h02RunMeasurement(',
            'function runH02RepresentativeRuntimeCli(',
        );

        // Provisioning is inside the already-proven testing boundary and
        // must return rows containing actual database IDs for k6.
        $provisionCall = strpos($measurement, 'h02ProvisionDatabaseFixtures(');
        $runtimeBoundary = strpos($measurement, 'H01LoadObservabilityHarness::assertRuntimeBoundary');
        $testingApplication = strpos($measurement, '$application->environment(\'testing\')');
        $testingDatabase = strpos($measurement, 'DB::connection()->getDatabaseName()');
        $fixtureJson = strpos($measurement, 'json_encode(');
        self::assertIsInt($provisionCall);
        self::assertIsInt($runtimeBoundary);
        self::assertIsInt($testingApplication);
        self::assertIsInt($testingDatabase);
        self::assertIsInt($fixtureJson);
        self::assertLessThan($provisionCall, $runtimeBoundary);
        self::assertLessThan($provisionCall, $testingApplication);
        self::assertLessThan($provisionCall, $testingDatabase);
        self::assertLessThan($fixtureJson, $provisionCall);
        self::assertMatchesRegularExpression(
            '/h02ProvisionDatabaseFixtures\(\s*array_slice\(\s*\$fixtureRows\s*,\s*0\s*,\s*\$options\[[\'\"]vus[\'\"]\]\s*\)\s*\)/s',
            $measurement,
        );
        self::assertMatchesRegularExpression(
            '/json_encode\(\s*array_slice\(\s*\$fixtureState\[[\'\"]rows[\'\"]\]/s',
            $measurement,
        );

        self::assertStringContainsString("'rows' =>", $provision);
        self::assertStringContainsString("'user_ids' =>", $provision);
        self::assertStringContainsString("'book_ids' =>", $provision);
        self::assertStringContainsString("'chapter_ids' =>", $provision);
        self::assertStringContainsString("'sense_ids' =>", $provision);
        self::assertStringContainsString("'review_card_ids' =>", $provision);
        self::assertStringContainsString('DB::transaction(', $provision);
        self::assertStringContainsString('User::factory()->create(', $provision);
        self::assertStringContainsString('\'email\' => $row[\'email\']', $provision);
        self::assertStringContainsString('\'password\' => $row[\'password\']', $provision);
        self::assertStringContainsString('\'selected_language\' => $row[\'language\']', $provision);
        self::assertStringContainsString("'is_admin' => false", $provision);
        self::assertStringContainsString('Book::forceCreate(', $provision);
        self::assertStringContainsString('Chapter::forceCreate(', $provision);
        self::assertStringContainsString('\'user_id\' => $user->id', $provision);
        self::assertStringContainsString('\'book_id\' => $book->id', $provision);
        self::assertStringContainsString("'processing_status' => 'processed'", $provision);
        self::assertStringContainsString('app(WordSenseService::class)->createSense(', $provision);
        self::assertStringContainsString('WordSense::STATUS_CONFIRMED', $provision);
        self::assertStringContainsString('app(ReviewCardService::class)->ensureSenseCard(', $provision);
        self::assertMatchesRegularExpression(
            '/[\'\"]chapter_id[\'\"]\s*=>\s*\$chapter->id/s',
            $provision,
        );
        self::assertMatchesRegularExpression(
            '/[\'\"]review_card_id[\'\"]\s*=>\s*\$reviewCard->id/s',
            $provision,
        );

        // Only users and cards need a distinctness invariant. Chapter and
        // lemma sharing remains an allowed implementation choice when the
        // current ownership rules make it safe.
        self::assertStringContainsString('array_unique($userIds)', $provision);
        self::assertStringContainsString('array_unique($reviewCardIds)', $provision);
        self::assertStringContainsString('H02_FIXTURE_IDENTITY_DUPLICATE', $provision);
        self::assertStringContainsString('count($userIds)', $provision);
        self::assertStringContainsString('count($reviewCardIds)', $provision);

        // Fixture setup must not create a formal review, log, or FSRS
        // schedule; the existing Sense Review endpoint owns that mutation.
        self::assertStringNotContainsString('ReviewLog::create', $provision);
        self::assertStringNotContainsString('recordReview(', $provision);
        self::assertStringNotContainsString('FsrsSchedulingService', $provision);
        self::assertStringNotContainsString('schedule(', $provision);
        self::assertStringNotContainsString('DB::table(', $provision);
        self::assertStringNotContainsString('Artisan::call(', $provision);
        self::assertStringNotContainsString('migrate:', $provision);
        self::assertStringNotContainsString('fwrite(', $provision);
        self::assertStringNotContainsString('emitEvidence(', $provision);

        // The only database cleanup owner is the H-02 child. It deletes
        // dependent rows by captured IDs, logs before cards, and proves that
        // its own users/cards are gone without touching existing test data.
        self::assertSame(2, substr_count($runner, 'h02CleanupDatabaseFixtures('));
        self::assertStringContainsString('ReviewLog::whereIn(', $cleanup);
        self::assertStringContainsString('ReviewCard::whereIn(', $cleanup);
        self::assertStringContainsString('WordSense::whereIn(', $cleanup);
        self::assertStringContainsString('Chapter::whereIn(', $cleanup);
        self::assertStringContainsString('Book::whereIn(', $cleanup);
        self::assertStringContainsString('User::whereIn(', $cleanup);
        self::assertStringNotContainsString('User::query()->delete()', $cleanup);
        self::assertStringNotContainsString('ReviewCard::query()->delete()', $cleanup);
        self::assertStringContainsString('H02_FIXTURE_CLEANUP_UNPROVEN', $cleanup);
        self::assertStringContainsString('->exists()', $cleanup);

        $measurementFinally = strpos($measurement, 'finally {');
        $k6Start = strpos($measurement, 'h01StartProcess(');
        $databaseCleanupCall = strpos($measurement, 'h02CleanupDatabaseFixtures(');
        self::assertIsInt($measurementFinally);
        self::assertIsInt($k6Start);
        self::assertIsInt($databaseCleanupCall);
        self::assertLessThan($databaseCleanupCall, $measurementFinally);
        self::assertLessThan($databaseCleanupCall, $k6Start);
        self::assertStringContainsString('is_array($fixtureState)', $measurement);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function sectionBetween(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        $end = $start === false ? false : strpos($source, $endMarker, $start + strlen($startMarker));
        if ($start === false || $end === false) {
            self::fail("H02 fixture lifecycle contract section is missing: {$startMarker}");
        }

        return substr($source, $start, $end - $start);
    }
}
