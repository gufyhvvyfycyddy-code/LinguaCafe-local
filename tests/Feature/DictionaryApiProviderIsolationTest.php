<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** R11R behavioral RED harness for provider-level isolation. */
class DictionaryApiProviderIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'R11R API Reader',
            'email' => 'r11r-api-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'r11r_api',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_one_malformed_provider_does_not_hide_a_healthy_provider(): void
    {
        $brokenId = $this->createCustomApiDictionary('R11R Broken Provider', 'https://r11r-broken.test');
        $this->createCustomApiDictionary('R11R Healthy Provider', 'https://r11r-healthy.test');

        Http::fake([
            'https://r11r-broken.test*' => Http::response('{not-json', 200),
            'https://r11r-healthy.test*' => Http::response(['translatedText' => 'healthy definition'], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/dictionaries/api/search', [
                'language' => 'japanese',
                'term' => '  friendly  ',
            ])
            ->assertOk();

        $response->assertJsonPath('term', 'friendly');
        $response->assertJsonPath('configured', true);
        $response->assertJsonPath('results.0.dictionary', 'R11R Healthy Provider');
        $response->assertJsonPath('results.0.definitions.0', 'healthy definition');
        $response->assertJsonPath('warnings.0.dictionary_id', $brokenId);
        $response->assertJsonPath('warnings.0.code', 'DICTIONARY_API_PROVIDER_FAILED');
        $this->assertStringNotContainsString('r11r-broken.test', $response->getContent());
        $this->assertStringNotContainsString('{not-json', $response->getContent());
    }

    public function test_all_configured_providers_unavailable_returns_safe_503(): void
    {
        $this->createCustomApiDictionary('R11R Broken Provider', 'https://r11r-all-broken.test');

        Http::fake([
            'https://r11r-all-broken.test*' => Http::response(['unexpected' => true], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/dictionaries/api/search', ['term' => 'friendly'])
            ->assertStatus(503);

        $response->assertJsonPath('error.code', 'DICTIONARY_LOOKUP_UNAVAILABLE');
        $this->assertStringNotContainsString('r11r-all-broken.test', $response->getContent());
    }

    public function test_no_api_provider_configured_returns_empty_success(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/dictionaries/api/search', ['term' => 'friendly'])
            ->assertOk();

        $response->assertJsonPath('term', 'friendly');
        $response->assertJsonPath('configured', false);
        $this->assertSame([], $response->json('results'));
        $this->assertSame([], $response->json('warnings'));
    }

    private function createCustomApiDictionary(string $name, string $host): int
    {
        return DB::table('dictionaries')->insertGetId([
            'name' => $name,
            'type' => 'custom_api',
            'api_host' => $host,
            'database_table_name' => 'API',
            'source_language' => 'r11r_api',
            'target_language' => 'english',
            'color' => '#abcdef',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
