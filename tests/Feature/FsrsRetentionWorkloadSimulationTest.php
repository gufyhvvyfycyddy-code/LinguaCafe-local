<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\ReviewLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WordSense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FsrsRetentionWorkloadSimulationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Sim Test User',
            'email' => '__VG_EMAIL_sim_test__',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function test_simulation_returns_empty_when_no_candidates(): void
    {
        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/retention-workload-simulation');

        $response->assertOk();
        $response->assertJsonPath('simulation_available', true);
        $response->assertJsonPath('total_candidates', 0);
        $this->assertCount(0, $response->json('options'));
    }

    public function test_simulation_returns_four_options_when_cards_exist(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $card = $this->createSenseCard($sense, [
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->addDays(1),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'fsrs_enabled' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/retention-workload-simulation');

        $response->assertOk();
        $response->assertJsonPath('simulation_available', true);
        $response->assertJsonPath('total_candidates', 1);

        $options = $response->json('options');
        $this->assertCount(4, $options);

        // Verify structure for each option
        $labels = [];
        foreach ($options as $opt) {
            $labels[] = $opt['label'];
            $this->assertArrayHasKey('retention', $opt);
            $this->assertArrayHasKey('today_due', $opt);
            $this->assertArrayHasKey('next7_due', $opt);
            $this->assertArrayHasKey('next7_delta_vs_current', $opt);
            $this->assertArrayHasKey('changed_cards', $opt);
            $this->assertArrayHasKey('recommendation', $opt);
            $this->assertArrayHasKey('message', $opt);
            $this->assertArrayHasKey('is_current', $opt);
            $this->assertIsInt($opt['today_due']);
            $this->assertIsInt($opt['next7_due']);
            $this->assertIsInt($opt['changed_cards']);
        }

        $this->assertContains('85%', $labels);
        $this->assertContains('90%', $labels);
        $this->assertContains('93%', $labels);
        $this->assertContains('95%', $labels);
    }

    public function test_simulation_does_not_write_to_database(): void
    {
        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense, [
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->addDays(1),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'fsrs_enabled' => true,
        ]);

        $cardCount = ReviewCard::count();
        $logCount = ReviewLog::count();

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/retention-workload-simulation');

        $response->assertOk();

        // Verify no data was written
        $this->assertEquals($cardCount, ReviewCard::count());
        $this->assertEquals($logCount, ReviewLog::count());
    }

    public function test_current_retention_is_marked(): void
    {
        // Save desired retention as 0.90
        Setting::forceCreate([
            'user_id' => -1,
            'name' => 'fsrsDesiredRetention',
            'value' => json_encode(0.90),
        ]);

        $sense = $this->createSense($this->user->id, 'english');
        $this->createSenseCard($sense, [
            'fsrs_state' => 'review',
            'fsrs_due_at' => Carbon::now()->addDays(1),
            'fsrs_stability' => 10.0,
            'fsrs_difficulty' => 5.0,
            'fsrs_last_reviewed_at' => Carbon::now()->subDays(3),
            'fsrs_enabled' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson('/settings/fsrs/retention-workload-simulation');

        $response->assertOk();
        $response->assertJsonPath('current_retention', 0.90);

        $options = $response->json('options');
        $foundCurrent = false;
        foreach ($options as $opt) {
            if ($opt['is_current']) {
                $this->assertEquals('90%', $opt['label']);
                $foundCurrent = true;
            }
        }
        $this->assertTrue($foundCurrent, 'Expected one option to be marked as current');
    }

    public function test_simulation_requires_auth(): void
    {
        $response = $this->postJson('/settings/fsrs/retention-workload-simulation');
        $response->assertStatus(401); // Unauthenticated
    }

    private function createSense(int $userId, string $language, array $overrides = []): WordSense
    {
        $lemma = $overrides['lemma'] ?? 'test';
        $pos = $overrides['pos'] ?? 'noun';
        $senseZh = $overrides['sense_zh'] ?? '测试';

        return WordSense::forceCreate(array_merge([
            'user_id' => $userId,
            'language' => $language,
            'language_id' => $language,
            'lemma' => $lemma,
            'surface_form' => $lemma,
            'pos' => $pos,
            'sense_zh' => $senseZh,
            'sense_en' => 'test',
            'aliases_zh' => [],
            'collocations' => [],
            'example_sentence_en' => 'Test.',
            'example_sentence_zh' => '测试。',
            'status' => WordSense::STATUS_CONFIRMED,
            'is_context_specific' => true,
            'sense_key' => hash('sha256', strtolower("{$language}|{$lemma}|{$pos}|{$senseZh}")),
        ], $overrides));
    }

    private function createSenseCard(WordSense $sense, array $overrides = []): ReviewCard
    {
        return ReviewCard::forceCreate(array_merge([
            'user_id' => $sense->user_id,
            'language_id' => $sense->language_id,
            'language' => $sense->language,
            'target_type' => ReviewCard::TARGET_SENSE,
            'target_id' => $sense->id,
            'fsrs_state' => 'new',
            'fsrs_due_at' => now(),
            'fsrs_enabled' => true,
        ], $overrides));
    }
}
