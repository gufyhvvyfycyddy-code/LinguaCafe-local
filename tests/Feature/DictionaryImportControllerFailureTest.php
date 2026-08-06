<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DictionaryImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DictionaryImportControllerFailureTest extends TestCase
{
    private User $admin;

    /** @var string[] */
    private array $createdTables = [];

    /** @var string[] */
    private array $createdDictionaryTableNames = [];

    /** @var int[] */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser(true);
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->createdTables) as $tableName) {
            Schema::dropIfExists($tableName);
        }

        if ($this->createdDictionaryTableNames !== []) {
            DB::table('dictionaries')
                ->whereIn('database_table_name', array_unique($this->createdDictionaryTableNames))
                ->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', array_unique($this->createdUserIds))->delete();
        }

        parent::tearDown();
    }

    public function test_service_exception_returns_500_without_deleting_live_dictionary(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $tableName = 'dict_r11_controller_'.$suffix;
        $payload = $this->validPayload([
            'dictionaryDatabaseName' => $tableName,
        ]);

        $this->createLiveDictionary($tableName, 'legacy definition');

        $this->mock(DictionaryImportService::class)
            ->shouldReceive('importSupportedDictionary')
            ->once()
            ->with(
                $this->admin->uuid,
                $payload['dictionaryName'],
                $payload['dictionaryFileName'],
                $payload['dictionarySourceLanguage'],
                $payload['dictionaryTargetLanguage'],
                $payload['dictionaryDatabaseName'],
            )
            ->andThrow(new RuntimeException('internal import detail must not trigger cleanup'));

        $response = $this->actingAs($this->admin)
            ->postJson('/dictionaries/import', $payload);

        $response->assertInternalServerError();
        $response->assertJsonPath('message', 'Dictionary import failed.');
        $this->assertStringNotContainsString(
            'internal import detail must not trigger cleanup',
            $response->getContent(),
        );
        $this->assertTrue(Schema::hasTable($tableName));
        $this->assertSame('legacy definition', DB::table($tableName)->value('definitions'));
        $this->assertSame(
            1,
            DB::table('dictionaries')->where('database_table_name', $tableName)->count(),
        );
    }

    public function test_service_false_result_is_not_reported_as_success(): void
    {
        $payload = $this->validPayload();

        $this->mock(DictionaryImportService::class)
            ->shouldReceive('importSupportedDictionary')
            ->once()
            ->with(
                $this->admin->uuid,
                $payload['dictionaryName'],
                $payload['dictionaryFileName'],
                $payload['dictionarySourceLanguage'],
                $payload['dictionaryTargetLanguage'],
                $payload['dictionaryDatabaseName'],
            )
            ->andReturnFalse();

        $response = $this->actingAs($this->admin)
            ->postJson('/dictionaries/import', $payload);

        $response->assertInternalServerError();
        $response->assertJsonPath('message', 'Dictionary import failed.');
        $this->assertNotSame(
            'Dictionary has been imported successfully.',
            $response->json(),
        );
    }

    public function test_service_true_result_preserves_success_contract_and_argument_order(): void
    {
        $payload = $this->validPayload();

        $this->mock(DictionaryImportService::class)
            ->shouldReceive('importSupportedDictionary')
            ->once()
            ->with(
                $this->admin->uuid,
                $payload['dictionaryName'],
                $payload['dictionaryFileName'],
                $payload['dictionarySourceLanguage'],
                $payload['dictionaryTargetLanguage'],
                $payload['dictionaryDatabaseName'],
            )
            ->andReturnTrue();

        $response = $this->actingAs($this->admin)
            ->postJson('/dictionaries/import', $payload);

        $response->assertOk();
        $this->assertSame('Dictionary has been imported successfully.', $response->json());
    }

    public function test_unauthenticated_user_cannot_call_supported_dictionary_import(): void
    {
        $this->mock(DictionaryImportService::class)
            ->shouldNotReceive('importSupportedDictionary');

        $this->postJson('/dictionaries/import', $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_non_admin_user_cannot_call_supported_dictionary_import(): void
    {
        $ordinaryUser = $this->createUser(false);

        $this->mock(DictionaryImportService::class)
            ->shouldNotReceive('importSupportedDictionary');

        $this->actingAs($ordinaryUser)
            ->postJson('/dictionaries/import', $this->validPayload())
            ->assertForbidden();
    }

    /** @param array<string, string> $overrides */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'dictionaryName' => 'cc-cedict',
            'dictionaryFileName' => 'cedict_ts.u8',
            'dictionarySourceLanguage' => 'chinese',
            'dictionaryTargetLanguage' => 'english',
            'dictionaryDatabaseName' => 'dict_zh_cedict',
        ], $overrides);
    }

    private function createLiveDictionary(string $tableName, string $definition): void
    {
        $this->createdTables[] = $tableName;
        $this->createdDictionaryTableNames[] = $tableName;

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('word', 256)->collation('utf8mb4_bin')->index();
            $table->string('definitions', 2048)->collation('utf8mb4_bin');
            $table->timestamps();
        });

        DB::table($tableName)->insert([
            'word' => 'legacy',
            'definitions' => $definition,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dictionaries')->insert([
            'name' => 'r11-controller-old',
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => $tableName,
            'source_language' => 'chinese',
            'target_language' => 'english',
            'color' => '#111111',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(bool $isAdmin): User
    {
        $user = User::forceCreate([
            'name' => $isAdmin ? 'R11 Controller Admin' : 'R11 Controller User',
            'email' => 'r11-controller-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => $isAdmin,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->createdUserIds[] = $user->id;

        return $user;
    }
}
