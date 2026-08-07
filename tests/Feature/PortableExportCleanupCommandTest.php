<?php

namespace Tests\Feature;

use App\Services\PortableExportWorkspaceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PortableExportCleanupCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r11w-command-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->app->instance(
            PortableExportWorkspaceService::class,
            new PortableExportWorkspaceService($this->root),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_command_is_dry_run_by_default_and_apply_deletes_only_owned_stale_workspaces(): void
    {
        $service = app(PortableExportWorkspaceService::class);
        CarbonImmutable::setTestNow('2026-08-07T00:00:00Z');
        $stale = $service->create(PortableExportWorkspaceService::KIND_ANKI_EXPORT);
        CarbonImmutable::setTestNow('2026-08-07T08:00:00Z');
        $fresh = $service->create(PortableExportWorkspaceService::KIND_PORTABLE_EXPORT);
        $invalid = $this->root.DIRECTORY_SEPARATOR.'portable-00000000-0000-4000-8000-000000000001';
        mkdir($invalid, 0700, true);
        file_put_contents($invalid.DIRECTORY_SEPARATOR.PortableExportWorkspaceService::MARKER_FILE, '{}');

        $this->artisan('portable:cleanup-export-workspaces', ['--min-age-hours' => 6])
            ->expectsOutput('mode=dry-run scanned=3 owned=2 stale=1 deleted=0 skipped=3 errors=0')
            ->assertExitCode(0);
        $this->assertDirectoryExists($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($invalid);

        $this->artisan('portable:cleanup-export-workspaces', [
            '--apply' => true,
            '--min-age-hours' => 6,
        ])->expectsOutput('mode=apply scanned=3 owned=2 stale=1 deleted=1 skipped=2 errors=0')
            ->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($invalid);
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
