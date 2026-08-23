<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\User;
use App\Models\WordSense;
use App\Services\Settings\FsrsOptimizationSettingsService;
use App\Services\Settings\Presets\ReviewSettingsResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class FsrsAutomaticOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-23 04:00:00 UTC'));
        $this->user = User::forceCreate([
            'name' => 'Automatic FSRS User',
            'email' => 'automatic-fsrs@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manual_mode_is_not_run_by_the_scheduled_command(): void
    {
        app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english');

        $this->artisan('fsrs:optimize-due')
            ->expectsOutputToContain('scanned=1 applied=0 failed=0')
            ->assertSuccessful();

        $config = app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english')->toArray();
        $this->assertSame('default', $config['fsrs']['parameters_source']);
        $this->assertNull($config['fsrs']['parameters_optimized_at']);
    }

    public function test_due_interval_uses_the_existing_optimizer_without_rescheduling_and_then_waits(): void
    {
        $card = $this->card();
        $this->logs($card, FsrsOptimizationSettingsService::MIN_REQUIRED);
        app(ReviewSettingsResolver::class)->mutate($this->user->id, 'english', ['fsrs' => [
            'optimization_mode' => 'interval',
            'optimization_interval_days' => 30,
        ]]);
        $beforeDue = $card->fsrs_due_at->toIso8601String();
        $beforeLogs = ReviewLog::count();

        $this->artisan('fsrs:optimize-due')
            ->expectsOutputToContain('scanned=1 applied=1 failed=0')
            ->assertSuccessful();

        $config = app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english')->toArray();
        $optimizedAt = $config['fsrs']['parameters_optimized_at'];
        $this->assertSame('optimized', $config['fsrs']['parameters_source']);
        $this->assertNotNull($optimizedAt);
        $this->assertSame($beforeDue, $card->fresh()->fsrs_due_at->toIso8601String());
        $this->assertSame($beforeLogs, ReviewLog::count());

        $this->artisan('fsrs:optimize-due')
            ->expectsOutputToContain('scanned=1 applied=0 failed=0')
            ->assertSuccessful();
        $this->assertSame(
            $optimizedAt,
            app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english')
                ->toArray()['fsrs']['parameters_optimized_at'],
        );
    }

    public function test_failed_attempt_is_visible_and_does_not_advance_success_time(): void
    {
        app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english');
        Log::spy();
        $this->mock(FsrsOptimizationSettingsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('applyAutomaticallyIfDue')
                ->once()
                ->with($this->user->id, 'english')
                ->andReturn([
                    'attempted' => true,
                    'applied' => false,
                    'reason' => 'optimization_failed',
                    'error_code' => 'COMPUTE_FAILED',
                ]);
        });

        $this->artisan('fsrs:optimize-due')
            ->expectsOutputToContain('scanned=1 applied=0 failed=1')
            ->assertFailed();
        Log::shouldHaveReceived('warning')->once()->with(
            'Automatic FSRS optimization attempt failed.',
            \Mockery::on(fn (array $context): bool => $context['user_id'] === $this->user->id
                && $context['language_id'] === 'english'
                && $context['error_code'] === 'COMPUTE_FAILED'),
        );

        $this->assertNull(
            app(ReviewSettingsResolver::class)->resolve($this->user->id, 'english')
                ->toArray()['fsrs']['parameters_optimized_at'],
        );
    }

    public function test_one_failed_binding_does_not_abort_the_next_english_binding(): void
    {
        $resolver = app(ReviewSettingsResolver::class);
        $resolver->mutate($this->user->id, 'english', ['fsrs' => [
            'optimization_mode' => 'interval',
            'optimization_interval_days' => 30,
        ]]);
        $second = User::forceCreate([
            'name' => 'Second Automatic FSRS User',
            'email' => 'second-automatic-fsrs@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
        ]);
        $secondCard = $this->card($second, 'second-automatic');
        $this->logs($secondCard, FsrsOptimizationSettingsService::MIN_REQUIRED);
        $resolver->mutate($second->id, 'english', ['fsrs' => [
            'optimization_mode' => 'interval',
            'optimization_interval_days' => 30,
        ]]);
        $secondDue = $secondCard->fsrs_due_at->toIso8601String();
        $beforeLogs = ReviewLog::count();
        $realOptimizer = app(FsrsOptimizationSettingsService::class);
        $mock = \Mockery::mock(FsrsOptimizationSettingsService::class);
        $mock->shouldReceive('applyAutomaticallyIfDue')
            ->twice()
            ->andReturnUsing(function (int $userId, string $language) use ($realOptimizer, $second): array {
                if ($userId === $this->user->id) {
                    return [
                        'attempted' => true,
                        'applied' => false,
                        'reason' => 'optimization_failed',
                        'error_code' => 'COMPUTE_FAILED',
                    ];
                }

                $this->assertSame($second->id, $userId);
                return $realOptimizer->applyAutomaticallyIfDue($userId, $language);
            });
        $this->app->instance(FsrsOptimizationSettingsService::class, $mock);

        $this->artisan('fsrs:optimize-due')
            ->expectsOutputToContain('scanned=2 applied=1 failed=1')
            ->assertFailed();

        $firstConfig = $resolver->resolve($this->user->id, 'english')->toArray()['fsrs'];
        $secondConfig = $resolver->resolve($second->id, 'english')->toArray()['fsrs'];
        $this->assertNull($firstConfig['parameters_optimized_at']);
        $this->assertSame('optimized', $secondConfig['parameters_source']);
        $this->assertNotNull($secondConfig['parameters_optimized_at']);
        $this->assertSame($secondDue, $secondCard->fresh()->fsrs_due_at->toIso8601String());
        $this->assertSame($beforeLogs, ReviewLog::count());
    }

    public function test_daily_schedule_registers_the_single_due_command(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('fsrs:optimize-due')
            ->assertSuccessful();
    }

    public function test_automatic_command_stays_inside_the_english_product_boundary(): void
    {
        app(ReviewSettingsResolver::class)->mutate($this->user->id, 'spanish', ['fsrs' => [
            'optimization_mode' => 'interval',
            'optimization_interval_days' => 30,
        ]]);

        $this->artisan('fsrs:optimize-due')
            ->expectsOutputToContain('scanned=0 applied=0 failed=0')
            ->assertSuccessful();

        $this->assertSame(
            'default',
            app(ReviewSettingsResolver::class)->resolve($this->user->id, 'spanish')
                ->toArray()['fsrs']['parameters_source'],
        );
    }

    private function card(?User $user = null, string $lemma = 'automatic'): ReviewCard
    {
        $user ??= $this->user;
        $sense = WordSense::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => 'adjective',
            'sense_zh' => '自动的',
            'sense_en' => 'working by itself',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'The process is automatic.',
            'example_sentence_zh' => '这个过程是自动的。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => false,
            'sense_key' => hash('sha256', "automatic-fsrs-test|{$user->id}|{$lemma}"),
        ]);

        return ReviewCard::forceCreate([
            'user_id' => $user->id,
            'language' => 'english',
            'language_id' => 'english',
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'review',
            'fsrs_due_at' => now()->addDays(3),
            'fsrs_last_reviewed_at' => now()->subDay(),
            'fsrs_stability' => 5,
            'fsrs_difficulty' => 6,
            'fsrs_reps' => 4,
            'fsrs_lapses' => 1,
            'fsrs_enabled' => true,
            'lifecycle_state' => 'active',
        ]);
    }

    private function logs(ReviewCard $card, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            ReviewLog::forceCreate([
                'user_id' => $card->user_id,
                'language' => $card->language,
                'language_id' => $card->language_id,
                'review_card_id' => $card->id,
                'rating' => $index % 5 === 0 ? 'again' : 'good',
                'reviewed_at' => now()->subDays($count - $index),
                'review_duration_ms' => 1000,
                'previous_state' => 'review',
                'new_state' => 'review',
                'source' => ReviewLog::SOURCE_SENSE_REVIEW,
            ]);
        }
    }
}
