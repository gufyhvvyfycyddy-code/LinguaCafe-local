<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class H02RepresentativeFixtureLifecycleTest extends TestCase
{
    public function test_real_h02_fixture_lifecycle_is_scoped_and_cleaned(): void
    {
        $controlUserId = null;

        try {
            $this->assertTrue(
                $this->app->environment('testing'),
                'APP_ENV must be "testing" before H-02 fixture writes.',
            );

            $dbName = Config::get('database.connections.mysql.database');
            $this->assertNotNull(
                $dbName,
                'No database name configured for mysql connection in testing env.',
            );
            $this->assertStringContainsString(
                'test',
                strtolower((string) $dbName),
                "Database '{$dbName}' does not look like a testing database. Aborting to protect real data.",
            );
            $this->assertNotSame(
                'linguacafe_fsrs',
                $dbName,
                'Testing database must not be the default "linguacafe_fsrs" database.',
            );

            $controlSuffix = bin2hex(random_bytes(8));
            $controlUser = User::forceCreate([
                'name' => "h02-control-{$controlSuffix}",
                'email' => "h02-control-{$controlSuffix}@example.test",
                'password' => bin2hex(random_bytes(16)),
                'selected_language' => 'en',
                'is_admin' => false,
            ]);
            $controlUserId = (int) $controlUser->getKey();

            $state = null;

            try {
                require_once __DIR__.'/../Support/run-h02-representative-runtime.php';

                $rows = h02PrepareFixtureRows(3);
                $state = h02ProvisionDatabaseFixtures($rows);

                $fixtureRows = $state['rows'];
                $userIds = $state['user_ids'];
                $bookIds = $state['book_ids'];
                $chapterIds = $state['chapter_ids'];
                $senseIds = $state['sense_ids'];
                $reviewCardIds = $state['review_card_ids'];

                $this->assertCount(3, $fixtureRows);
                $this->assertCount(3, $userIds);
                $this->assertCount(3, array_unique($userIds));
                $this->assertCount(3, $bookIds);
                $this->assertCount(3, $chapterIds);
                $this->assertCount(3, $senseIds);
                $this->assertCount(3, $reviewCardIds);
                $this->assertCount(3, array_unique($reviewCardIds));

                foreach ($fixtureRows as $index => $runtimeRow) {
                    $this->assertIsArray($runtimeRow);

                    $user = User::find($userIds[$index]);
                    $chapter = Chapter::find($runtimeRow['chapter_id']);
                    $sense = WordSense::find($senseIds[$index]);
                    $reviewCard = ReviewCard::find($runtimeRow['review_card_id']);

                    $this->assertInstanceOf(User::class, $user);
                    $this->assertInstanceOf(Chapter::class, $chapter);
                    $this->assertInstanceOf(WordSense::class, $sense);
                    $this->assertInstanceOf(ReviewCard::class, $reviewCard);
                    $this->assertSame($runtimeRow['email'], $user->email);
                    $this->assertSame((int) $userIds[$index], (int) $user->getKey());
                    $this->assertSame((int) $chapterIds[$index], (int) $chapter->getKey());
                    $this->assertSame((int) $senseIds[$index], (int) $sense->getKey());
                    $this->assertSame((int) $reviewCardIds[$index], (int) $reviewCard->getKey());
                    $this->assertSame((int) $userIds[$index], (int) $reviewCard->user_id);

                    if (array_key_exists('user_id', $runtimeRow)) {
                        $this->assertSame(
                            (int) $runtimeRow['user_id'],
                            (int) $reviewCard->user_id,
                        );
                    }

                    $reviewCardAttributes = $reviewCard->getAttributes();
                    if (array_key_exists('target_type', $reviewCardAttributes)
                        && array_key_exists('target_id', $reviewCardAttributes)
                    ) {
                        $this->assertSame(ReviewCard::TARGET_SENSE, $reviewCard->target_type);
                        $this->assertSame((int) $sense->getKey(), (int) $reviewCard->target_id);
                    }
                }

                $this->assertFalse(
                    ReviewLog::whereIn('review_card_id', $reviewCardIds)->exists(),
                    'Fixture provisioning must not create ReviewLog rows.',
                );
            } finally {
                if (is_array($state)) {
                    h02CleanupDatabaseFixtures($state);

                    $this->assertFalse(User::whereIn('id', $userIds)->exists());
                    $this->assertFalse(Book::whereIn('id', $bookIds)->exists());
                    $this->assertFalse(Chapter::whereIn('id', $chapterIds)->exists());
                    $this->assertFalse(WordSense::whereIn('id', $senseIds)->exists());
                    $this->assertFalse(ReviewCard::whereIn('id', $reviewCardIds)->exists());
                    $this->assertFalse(
                        ReviewLog::whereIn('review_card_id', $reviewCardIds)->exists(),
                    );
                    $this->assertTrue(
                        User::whereKey($controlUserId)->exists(),
                        'H-02 cleanup must leave the control User untouched.',
                    );
                }
            }
        } finally {
            if ($controlUserId !== null) {
                $controlUser = User::find($controlUserId);
                $this->assertNotNull($controlUser, 'Control User disappeared unexpectedly.');
                $this->assertTrue($controlUser->delete(), 'Control User deletion failed.');
                $this->assertFalse(User::whereKey($controlUserId)->exists());
            }
        }
    }
}
