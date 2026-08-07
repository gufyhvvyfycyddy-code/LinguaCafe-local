<?php

namespace Tests\Unit;

use App\Services\PortableExportWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class PortableExportWorkspaceServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r11w-workspaces-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_create_writes_a_valid_non_sensitive_ownership_marker(): void
    {
        CarbonImmutable::setTestNow('2026-08-07T05:00:00Z');
        $service = new PortableExportWorkspaceService($this->root);

        $directory = $service->create(PortableExportWorkspaceService::KIND_ANKI_EXPORT);
        $markerPath = $directory.DIRECTORY_SEPARATOR.PortableExportWorkspaceService::MARKER_FILE;
        $marker = json_decode((string) file_get_contents($markerPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertDirectoryExists($directory);
        $this->assertFileExists($markerPath);
        $this->assertSame(PortableExportWorkspaceService::SCHEMA_VERSION, $marker['schema_version']);
        $this->assertSame(PortableExportWorkspaceService::KIND_ANKI_EXPORT, $marker['kind']);
        $this->assertSame('2026-08-07T05:00:00.000000Z', $marker['created_at']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $marker['workspace_id']);
        $this->assertSame(['schema_version', 'kind', 'created_at', 'workspace_id'], array_keys($marker));
        $this->assertStringNotContainsString('user', json_encode($marker));
        $this->assertStringNotContainsString('email', json_encode($marker));
        $this->assertStringNotContainsString($this->root, json_encode($marker));
    }

    public function test_cleanup_requires_a_valid_owned_top_level_workspace_and_is_idempotent(): void
    {
        $service = new PortableExportWorkspaceService($this->root);
        $owned = $service->create(PortableExportWorkspaceService::KIND_PORTABLE_EXPORT);
        file_put_contents($owned.DIRECTORY_SEPARATOR.'package.lcpkg', 'package');
        mkdir($owned.DIRECTORY_SEPARATOR.'nested');
        file_put_contents($owned.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'part', 'part');

        $unowned = $this->root.DIRECTORY_SEPARATOR.'portable-'.Str::uuid();
        mkdir($unowned, 0700, true);
        file_put_contents($unowned.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r11w-outside-'.bin2hex(random_bytes(6));
        mkdir($outside, 0700, true);
        file_put_contents($outside.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        try {
            $this->assertTrue($service->cleanup($owned));
            $this->assertDirectoryDoesNotExist($owned);
            $this->assertTrue($service->cleanup($owned));
            $this->assertFalse($service->cleanup($unowned));
            $this->assertDirectoryExists($unowned);
            $this->assertFalse($service->cleanup($outside));
            $this->assertDirectoryExists($outside);
        } finally {
            $this->removeTree($outside);
        }
    }

    public function test_scan_is_dry_run_by_default_and_apply_deletes_only_stale_owned_workspaces(): void
    {
        CarbonImmutable::setTestNow('2026-08-07T00:00:00Z');
        $service = new PortableExportWorkspaceService($this->root);
        $stale = $service->create(PortableExportWorkspaceService::KIND_ANKI_EXPORT);

        CarbonImmutable::setTestNow('2026-08-07T08:00:00Z');
        $fresh = $service->create(PortableExportWorkspaceService::KIND_PORTABLE_EXPORT);
        $invalid = $this->root.DIRECTORY_SEPARATOR.'anki-'.Str::uuid();
        mkdir($invalid, 0700, true);
        file_put_contents($invalid.DIRECTORY_SEPARATOR.PortableExportWorkspaceService::MARKER_FILE, '{bad json');
        mkdir($this->root.DIRECTORY_SEPARATOR.'unrelated-directory', 0700, true);

        $dryRun = $service->cleanupStale(6, false);
        $this->assertSame([
            'scanned' => 3,
            'owned' => 2,
            'stale' => 1,
            'deleted' => 0,
            'skipped' => 3,
            'errors' => 0,
        ], $dryRun);
        $this->assertDirectoryExists($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($invalid);

        $applied = $service->cleanupStale(6, true);
        $this->assertSame([
            'scanned' => 3,
            'owned' => 2,
            'stale' => 1,
            'deleted' => 1,
            'skipped' => 2,
            'errors' => 0,
        ], $applied);
        $this->assertDirectoryDoesNotExist($stale);
        $this->assertDirectoryExists($fresh);
        $this->assertDirectoryExists($invalid);
    }

    public function test_cleanup_rejects_unknown_marker_schema_and_non_utc_timestamp(): void
    {
        $service = new PortableExportWorkspaceService($this->root);
        $unknownSchema = $service->create(PortableExportWorkspaceService::KIND_ANKI_EXPORT);
        $unknownMarker = json_decode(
            (string) file_get_contents($unknownSchema.DIRECTORY_SEPARATOR.PortableExportWorkspaceService::MARKER_FILE),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $unknownMarker['schema_version'] = 99;
        file_put_contents(
            $unknownSchema.DIRECTORY_SEPARATOR.PortableExportWorkspaceService::MARKER_FILE,
            json_encode($unknownMarker, JSON_THROW_ON_ERROR),
        );

        $nonUtc = $service->create(PortableExportWorkspaceService::KIND_PORTABLE_EXPORT);
        $nonUtcMarker = json_decode(
            (string) file_get_contents($nonUtc.DIRECTORY_SEPARATOR.PortableExportWorkspaceService::MARKER_FILE),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $nonUtcMarker['created_at'] = '2026-08-07T08:00:00+08:00';
        file_put_contents(
            $nonUtc.DIRECTORY_SEPARATOR.PortableExportWorkspaceService::MARKER_FILE,
            json_encode($nonUtcMarker, JSON_THROW_ON_ERROR),
        );

        $this->assertFalse($service->cleanup($unknownSchema));
        $this->assertFalse($service->cleanup($nonUtc));
        $this->assertDirectoryExists($unknownSchema);
        $this->assertDirectoryExists($nonUtc);
    }

    public function test_top_level_symlink_is_skipped_without_following_target(): void
    {
        $service = new PortableExportWorkspaceService($this->root);
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r11w-top-link-target-'.bin2hex(random_bytes(6));
        mkdir($outside, 0700, true);
        $outsideFile = $outside.DIRECTORY_SEPARATOR.'keep.txt';
        file_put_contents($outsideFile, 'keep');
        $link = $this->root.DIRECTORY_SEPARATOR.'anki-'.Str::uuid();

        if (! @symlink($outside, $link)) {
            $this->removeTree($outside);
            $this->markTestSkipped('Filesystem symlinks are unavailable in this environment.');
        }

        try {
            $this->assertFalse($service->cleanup($link));
            $this->assertSame([
                'scanned' => 1,
                'owned' => 0,
                'stale' => 0,
                'deleted' => 0,
                'skipped' => 1,
                'errors' => 0,
            ], $service->cleanupStale(6, true));
            $this->assertFileExists($outsideFile);
        } finally {
            @rmdir($link);
            $this->removeTree($outside);
        }
    }

    public function test_cleanup_does_not_follow_a_nested_symlink(): void
    {
        $service = new PortableExportWorkspaceService($this->root);
        $owned = $service->create(PortableExportWorkspaceService::KIND_ANKI_EXPORT);
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'r11w-symlink-target-'.bin2hex(random_bytes(6));
        mkdir($outside, 0700, true);
        $outsideFile = $outside.DIRECTORY_SEPARATOR.'keep.txt';
        file_put_contents($outsideFile, 'keep');
        $link = $owned.DIRECTORY_SEPARATOR.'linked-target';

        if (! @symlink($outside, $link)) {
            $this->removeTree($outside);
            $this->markTestSkipped('Filesystem symlinks are unavailable in this environment.');
        }

        try {
            $this->assertTrue($service->cleanup($owned));
            $this->assertFileExists($outsideFile);
            $this->assertDirectoryDoesNotExist($owned);
        } finally {
            $this->removeTree($outside);
        }
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
