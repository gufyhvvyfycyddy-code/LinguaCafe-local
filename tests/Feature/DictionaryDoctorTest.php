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

/** R11R behavioral RED harness for the admin-only read-only doctor. */
class DictionaryDoctorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reader;

    /** @var string[] */
    private array $createdTables = [];

    /** @var int[] */
    private array $createdMetadataIds = [];

    /** @var int[] */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser(true);
        $this->reader = $this->createUser(false);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse(array_unique($this->createdTables)) as $tableName) {
            Schema::dropIfExists($tableName);
        }

        if ($this->createdMetadataIds !== []) {
            DB::table('dictionaries')->whereIn('id', array_unique($this->createdMetadataIds))->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('users')->whereIn('id', array_unique($this->createdUserIds))->delete();
        }

        parent::tearDown();
    }

    public function test_doctor_is_admin_only_get_only_and_returns_stable_read_only_evidence(): void
    {
        $goodTable = $this->uniqueTable('doctor_good');
        $missingTable = $this->uniqueTable('doctor_missing');
        $orphanTable = $this->uniqueTable('doctor_orphan');
        $this->createDictionaryTable($goodTable);
        $this->createDictionaryTable($orphanTable);
        $goodId = $this->createMetadata('R11R Doctor Good', $goodTable);
        $missingId = $this->createMetadata('R11R Doctor Missing', $missingTable);

        $before = $this->databaseFingerprint();

        $first = $this->actingAs($this->admin)->getJson('/dictionaries/doctor');
        $this->assertSame(200, $first->status(), $first->getContent());
        $second = $this->actingAs($this->admin)->getJson('/dictionaries/doctor');
        $this->assertSame(200, $second->status(), $second->getContent());

        $after = $this->databaseFingerprint();
        $this->assertSame($before, $after, 'Doctor must not mutate metadata or tables.');
        $this->assertSame($first->json('evidence_hash'), $second->json('evidence_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first->json('evidence_hash'));

        $metadata = collect($first->json('metadata'));
        $this->assertSame('healthy', $metadata->firstWhere('id', $goodId)['health']['status'] ?? null);
        $this->assertSame('missing_table', $metadata->firstWhere('id', $missingId)['health']['status'] ?? null);
        $this->assertContains($orphanTable, collect($first->json('orphans'))->pluck('target')->all());
        $this->assertTrue($first->json('read_only'));
        $this->assertFalse($first->json('repair_available'));

        $this->postJson('/dictionaries/doctor')
            ->assertStatus(405);
    }

    public function test_doctor_rejects_non_admin_user(): void
    {
        $this->actingAs($this->reader)
            ->getJson('/dictionaries/doctor')
            ->assertForbidden();
    }

    public function test_doctor_reports_duplicate_targets_without_changing_them(): void
    {
        $table = $this->uniqueTable('doctor_duplicate');
        $this->createDictionaryTable($table);
        $firstId = $this->createMetadata('R11R Doctor Duplicate A', $table);
        $secondId = $this->createMetadata('R11R Doctor Duplicate B', $table);

        $response = $this->actingAs($this->admin)
            ->getJson('/dictionaries/doctor')
            ->assertOk();

        $metadata = collect($response->json('metadata'));
        foreach ([$firstId, $secondId] as $id) {
            $this->assertSame(
                'duplicate_target',
                $metadata->firstWhere('id', $id)['health']['status'] ?? null,
            );
        }
        $this->assertSame(2, DB::table('dictionaries')->where('database_table_name', $table)->count());
        $this->assertTrue(Schema::hasTable($table));
    }

    private function createUser(bool $admin): User
    {
        $user = User::forceCreate([
            'name' => $admin ? 'R11R Doctor Admin' : 'R11R Doctor Reader',
            'email' => 'r11r-doctor-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => $admin,
            'uuid' => (string) Str::uuid(),
        ]);

        $this->createdUserIds[] = $user->id;

        return $user;
    }

    private function createDictionaryTable(string $tableName): void
    {
        $this->createdTables[] = $tableName;
        Schema::create($tableName, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('word', 256)->index();
            $blueprint->string('definitions', 2048);
            $blueprint->timestamps();
        });
    }

    private function createMetadata(string $name, string $tableName): int
    {
        $id = DB::table('dictionaries')->insertGetId([
            'name' => $name,
            'type' => 'supported',
            'api_host' => null,
            'database_table_name' => $tableName,
            'source_language' => 'english',
            'target_language' => 'english',
            'color' => '#654321',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdMetadataIds[] = $id;

        return $id;
    }

    private function uniqueTable(string $purpose): string
    {
        return 'dict_r11r_'.$purpose.'_'.bin2hex(random_bytes(4));
    }

    /** @return array{metadata: array<int, array<string, mixed>>, tables: string[]} */
    private function databaseFingerprint(): array
    {
        return [
            'metadata' => DB::table('dictionaries')
                ->orderBy('id')
                ->get()
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'tables' => DB::table('information_schema.tables')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->orderBy('table_name')
                ->pluck('table_name')
                ->all(),
        ];
    }
}
