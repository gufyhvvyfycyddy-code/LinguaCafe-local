<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R11R behavioral RED harness for dictionary read-path isolation.
 *
 * Every dynamic table has a unique task prefix and is removed in tearDown.
 * These tests exercise public HTTP behavior, so failures describe product
 * behavior rather than missing implementation classes.
 */
class DictionaryReadPathDegradedAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reader;

    /** @var string[] */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(true, 'english');
        $this->reader = $this->createUser(false, 'english');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(array_unique($this->createdTables)) as $tableName) {
            Schema::dropIfExists($tableName);
        }

        parent::tearDown();
    }

    public function test_admin_list_returns_healthy_and_missing_rows_without_losing_the_list(): void
    {
        $goodTable = $this->uniqueTable('good');
        $missingTable = $this->uniqueTable('missing');
        $this->createDictionaryTable($goodTable, [
            ['word' => 'alpha', 'definitions' => 'first'],
        ]);
        $goodId = $this->createMetadata('R11R Good', $goodTable, true);
        $badId = $this->createMetadata('R11R Missing', $missingTable, true);

        $response = $this->actingAs($this->admin)
            ->getJson('/dictionaries/get')
            ->assertOk();

        $rows = collect($response->json());
        $good = $rows->firstWhere('id', $goodId);
        $bad = $rows->firstWhere('id', $badId);

        $this->assertSame(1, $good['records'] ?? null);
        $this->assertSame('healthy', $good['health']['status'] ?? null);
        $this->assertTrue($good['health']['query_available'] ?? false);

        $this->assertArrayHasKey('records', $bad);
        $this->assertNull($bad['records']);
        $this->assertSame('missing_table', $bad['health']['status'] ?? null);
        $this->assertFalse($bad['health']['query_available'] ?? true);
        $this->assertTrue($bad['health']['repair_required'] ?? false);
        $this->assertSafePayload($response->getContent());
    }

    public function test_disabled_missing_dictionary_does_not_break_admin_list(): void
    {
        $missingTable = $this->uniqueTable('disabled_missing');
        $id = $this->createMetadata('R11R Disabled Missing', $missingTable, false);

        $response = $this->actingAs($this->admin)
            ->getJson('/dictionaries/get')
            ->assertOk();

        $row = collect($response->json())->firstWhere('id', $id);

        $this->assertSame('missing_table', $row['health']['status'] ?? null);
        $this->assertFalse($row['health']['query_available'] ?? true);
        $this->assertArrayHasKey('records', $row);
        $this->assertNull($row['records']);
    }

    public function test_missing_dictionary_id_returns_stable_not_found_contract(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/dictionaries/get/999999999')
            ->assertNotFound();

        $response->assertJsonPath('error.code', 'DICTIONARY_NOT_FOUND');
        $this->assertSafePayload($response->getContent());
    }

    public function test_duplicate_metadata_targets_are_both_marked_unavailable(): void
    {
        $table = $this->uniqueTable('duplicate');
        $this->createDictionaryTable($table, [
            ['word' => 'shared', 'definitions' => 'definition'],
        ]);
        $firstId = $this->createMetadata('R11R Duplicate A', $table, true);
        $secondId = $this->createMetadata('R11R Duplicate B', $table, true);

        $response = $this->actingAs($this->admin)
            ->getJson('/dictionaries/get')
            ->assertOk();

        $rows = collect($response->json());
        foreach ([$firstId, $secondId] as $id) {
            $row = $rows->firstWhere('id', $id);
            $this->assertSame('duplicate_target', $row['health']['status'] ?? null);
            $this->assertFalse($row['health']['query_available'] ?? true);
            $this->assertArrayHasKey('records', $row);
            $this->assertNull($row['records']);
        }
    }

    public function test_invalid_schema_is_isolated_and_never_read_as_dictionary_content(): void
    {
        $table = $this->uniqueTable('invalid_schema');
        $this->createdTables[] = $table;
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('secret_value');
        });
        DB::table($table)->insert(['secret_value' => 'r11r-sensitive-marker']);
        $id = $this->createMetadata('R11R Invalid', $table, true);

        $response = $this->actingAs($this->admin)
            ->getJson('/dictionaries/get')
            ->assertOk();

        $row = collect($response->json())->firstWhere('id', $id);
        $this->assertSame('invalid_schema', $row['health']['status'] ?? null);
        $this->assertArrayHasKey('records', $row);
        $this->assertNull($row['records']);
        $this->assertStringNotContainsString('r11r-sensitive-marker', $response->getContent());
        $this->assertSafePayload($response->getContent());
    }

    public function test_good_local_dictionary_survives_a_bad_enabled_dictionary_with_safe_warning(): void
    {
        $goodTable = $this->uniqueTable('lookup_good');
        $missingTable = $this->uniqueTable('lookup_missing');
        $this->createDictionaryTable($goodTable, [
            ['word' => 'friendly', 'definitions' => 'kind; pleasant'],
        ]);
        $this->createMetadata('R11R Healthy Lookup', $goodTable, true, 'english');
        $badId = $this->createMetadata('R11R Broken Lookup', $missingTable, true, 'english');

        $response = $this->actingAs($this->reader)
            ->postJson('/dictionaries/search', [
                'language' => 'japanese',
                'term' => '  friendly  ',
            ])
            ->assertOk();

        $response->assertJsonPath('term', 'friendly');
        $response->assertJsonPath('configured', true);
        $this->assertSame('R11R Healthy Lookup', $response->json('results.0.name'));
        $this->assertSame(['kind', 'pleasant'], $response->json('results.0.records.0.definitions'));
        $this->assertSame($badId, $response->json('warnings.0.dictionary_id'));
        $this->assertSame('DICTIONARY_TABLE_MISSING', $response->json('warnings.0.code'));
        $this->assertSafePayload($response->getContent(), [$missingTable]);
    }

    public function test_all_configured_local_dictionaries_unavailable_returns_safe_503(): void
    {
        $missingTable = $this->uniqueTable('all_bad');
        $this->createMetadata('R11R All Bad', $missingTable, true, 'english');

        $response = $this->actingAs($this->reader)
            ->postJson('/dictionaries/search-for-hover-vocabulary', [
                'language' => 'english',
                'term' => 'friendly',
            ])
            ->assertStatus(503);

        $response->assertJsonPath('error.code', 'DICTIONARY_LOOKUP_UNAVAILABLE');
        $this->assertSafePayload($response->getContent(), [$missingTable]);
    }

    public function test_no_configured_dictionary_returns_empty_success_distinct_from_outage(): void
    {
        $response = $this->actingAs($this->reader)
            ->postJson('/dictionaries/search-for-hover-vocabulary', [
                'language' => 'english',
                'term' => 'friendly',
            ])
            ->assertOk();

        $response->assertJsonPath('term', 'friendly');
        $response->assertJsonPath('configured', false);
        $this->assertSame([], $response->json('definitions'));
        $this->assertSame([], $response->json('warnings'));
    }

    public function test_disabled_broken_dictionary_is_not_queried_or_reported_as_runtime_warning(): void
    {
        $missingTable = $this->uniqueTable('disabled_lookup');
        $this->createMetadata('R11R Disabled Broken', $missingTable, false, 'english');

        $response = $this->actingAs($this->reader)
            ->postJson('/dictionaries/search', [
                'language' => 'english',
                'term' => 'friendly',
            ])
            ->assertOk();

        $response->assertJsonPath('configured', false);
        $this->assertSame([], $response->json('results'));
        $this->assertSame([], $response->json('warnings'));
    }

    public function test_record_count_rejects_core_and_auxiliary_tables_but_allows_owned_healthy_target(): void
    {
        $ownedTable = $this->uniqueTable('count_owned');
        $auxiliaryTable = $ownedTable.'_stage_deadbeef';
        $this->createDictionaryTable($ownedTable, [
            ['word' => 'one', 'definitions' => 'first'],
            ['word' => 'two', 'definitions' => 'second'],
        ]);
        $this->createDictionaryTable($auxiliaryTable, []);
        $this->createMetadata('R11R Count', $ownedTable, true);

        $this->actingAs($this->admin)
            ->getJson('/dictionaries/get-record-count/'.$ownedTable)
            ->assertOk()
            ->assertJsonPath('count', 2);

        $this->actingAs($this->admin)
            ->getJson('/dictionaries/get-record-count/users')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DICTIONARY_RECORD_COUNT_NOT_ALLOWED');

        $this->actingAs($this->admin)
            ->getJson('/dictionaries/get-record-count/'.$auxiliaryTable)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DICTIONARY_RECORD_COUNT_NOT_ALLOWED');
    }

    public function test_web_lookup_validation_trims_and_rejects_blank_overlong_or_control_terms(): void
    {
        $table = $this->uniqueTable('validation');
        $this->createDictionaryTable($table, [
            ['word' => 'term', 'definitions' => 'definition'],
        ]);
        $this->createMetadata('R11R Validation', $table, true, 'english');

        $this->actingAs($this->reader)
            ->postJson('/dictionaries/search', ['language' => 'english', 'term' => " \t\n "])
            ->assertUnprocessable();

        $this->actingAs($this->reader)
            ->postJson('/dictionaries/search', ['language' => 'english', 'term' => str_repeat('é', 101)])
            ->assertUnprocessable();

        $this->actingAs($this->reader)
            ->postJson('/dictionaries/search', ['language' => 'english', 'term' => "bad\0term"])
            ->assertUnprocessable();

        $this->actingAs($this->reader)
            ->postJson('/dictionaries/search', ['language' => 'english', 'term' => "bad\x01term"])
            ->assertUnprocessable();
    }

    private function createUser(bool $admin, string $language): User
    {
        return User::forceCreate([
            'name' => $admin ? 'R11R Admin' : 'R11R Reader',
            'email' => 'r11r-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => $language,
            'password_changed' => true,
            'is_admin' => $admin,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    /** @param array<int, array{word: string, definitions: string}> $rows */
    private function createDictionaryTable(string $tableName, array $rows): void
    {
        $this->createdTables[] = $tableName;
        Schema::create($tableName, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('word', 256)->collation('utf8mb4_bin')->index();
            $blueprint->string('definitions', 2048)->collation('utf8mb4_bin');
            $blueprint->timestamps();
        });

        foreach ($rows as $row) {
            DB::table($tableName)->insert([
                ...$row,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createMetadata(
        string $name,
        string $tableName,
        bool $enabled,
        string $sourceLanguage = 'english',
    ): int {
        return DB::table('dictionaries')->insertGetId([
            'name' => $name,
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => $tableName,
            'source_language' => $sourceLanguage,
            'target_language' => 'english',
            'color' => '#123456',
            'enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function uniqueTable(string $purpose): string
    {
        return 'dict_r11r_'.$purpose.'_'.bin2hex(random_bytes(4));
    }

    /** @param string[] $extraForbidden */
    private function assertSafePayload(string $payload, array $extraForbidden = []): void
    {
        foreach (array_merge([
            'SQLSTATE',
            'information_schema',
            'Base table or view not found',
            'C:\\',
            '/storage/',
        ], $extraForbidden) as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }
}
