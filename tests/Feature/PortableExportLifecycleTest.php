<?php

namespace Tests\Feature;

use App\Models\ReviewLog;
use App\Models\User;
use App\Services\AnkiWordSensePackageService;
use App\Services\BackupService;
use App\Services\MediaAssetService;
use App\Services\PortableDataImportPlanService;
use App\Services\PortableDataService;
use App\Services\PortableExportWorkspaceService;
use App\Services\ReviewCardManageItemSerializerService;
use App\Services\ReviewCardManageQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PortableExportLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::forceCreate([
            'name' => 'R11W Export Admin',
            'email' => 'r11w-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => true,
            'uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_all_portable_head_requests_fail_before_business_collaborators_or_temp_creation(): void
    {
        $before = $this->exportWorkspaces();
        $beforeLogs = ReviewLog::count();

        $this->mock(ReviewCardManageQueryService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('parseCriteriaForState', 'buildFromFilterState');
        });
        $this->mock(ReviewCardManageItemSerializerService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('buildItems');
        });
        $this->mock(AnkiWordSensePackageService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('build');
        });
        $this->mock(PortableDataService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('contentEnvelope', 'buildFullPackage');
        });

        foreach ([
            '/review-cards/manage/portable/export-anki',
            '/review-cards/manage/portable/export-json',
            '/review-cards/manage/portable/export-csv',
            '/review-cards/manage/portable/export-full',
        ] as $uri) {
            $this->actingAs($this->admin)->call('HEAD', $uri)->assertStatus(405);
        }

        $this->assertSame($before, $this->exportWorkspaces());
        $this->assertSame($beforeLogs, ReviewLog::count());
    }

    public function test_normal_apkg_and_full_package_gets_stream_and_cleanup_owned_workspaces(): void
    {
        $before = $this->exportWorkspaces();

        $anki = $this->actingAs($this->admin)
            ->get('/review-cards/manage/portable/export-anki')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertNotSame('', $anki->streamedContent());
        $this->assertSame($before, $this->exportWorkspaces());

        $full = $this->actingAs($this->admin)
            ->get('/review-cards/manage/portable/export-full')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');
        $this->assertNotSame('', $full->streamedContent());
        $this->assertSame($before, $this->exportWorkspaces());
        $this->assertDatabaseCount('review_logs', 0);
    }

    public function test_stream_callback_failure_still_calls_package_cleanup(): void
    {
        $missing = storage_path('app/temp/anki-r11w-missing/package.apkg');
        $this->mock(AnkiWordSensePackageService::class, function (MockInterface $mock) use ($missing): void {
            $mock->shouldReceive('build')->once()->andReturn([
                'path' => $missing,
                'count' => 0,
                'sha256' => str_repeat('a', 64),
            ]);
            $mock->shouldReceive('cleanupPackage')->once()->with($missing);
        });

        $response = $this->actingAs($this->admin)
            ->get('/review-cards/manage/portable/export-anki')
            ->assertOk();

        ob_start();
        try {
            ($response->baseResponse->getCallback())();
            $this->fail('Expected the missing stream source to fail.');
        } catch (\Illuminate\Routing\Exceptions\StreamedResponseException) {
            $this->addToAssertionCount(1);
        } finally {
            ob_end_clean();
        }
    }

    public function test_build_failures_cleanup_new_owned_workspaces(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r11w-build-failure-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $workspaces = new PortableExportWorkspaceService($root);

        try {
            $anki = new AnkiWordSensePackageService($workspaces);
            try {
                $anki->build(new Collection([['word_sense_id' => 0]]));
                $this->fail('Expected invalid Anki item failure.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            $this->assertSame([], $this->childNames($root));

            $plan = Mockery::mock(PortableDataImportPlanService::class);
            $plan->shouldReceive('portableUserFingerprint')->andThrow(new RuntimeException('planned build failure'));
            $portable = new PortableDataService(
                Mockery::mock(AnkiWordSensePackageService::class),
                Mockery::mock(BackupService::class),
                $plan,
                Mockery::mock(MediaAssetService::class),
                $workspaces,
            );
            try {
                $portable->buildFullPackage([], $this->admin->id, 'english');
                $this->fail('Expected portable package build failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame('planned build failure', $exception->getMessage());
            }
            $this->assertSame([], $this->childNames($root));
        } finally {
            $this->removeTree($root);
        }
    }

    private function exportWorkspaces(): array
    {
        $root = storage_path('app/temp');
        if (! is_dir($root)) {
            return [];
        }
        $names = array_values(array_filter(
            array_diff(scandir($root) ?: [], ['.', '..']),
            fn (string $name): bool => str_starts_with($name, 'anki-') || str_starts_with($name, 'portable-'),
        ));
        sort($names);
        return $names;
    }

    private function childNames(string $root): array
    {
        $names = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
        sort($names);
        return $names;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }
}
