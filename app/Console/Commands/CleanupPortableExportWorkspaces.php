<?php

namespace App\Console\Commands;

use App\Services\PortableExportWorkspaceService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CleanupPortableExportWorkspaces extends Command
{
    protected $signature = 'portable:cleanup-export-workspaces
        {--apply : Delete stale owned export workspaces instead of reporting them}
        {--min-age-hours=6 : Minimum workspace age in hours; must be at least 1}';

    protected $description = 'Inspect or delete stale LinguaCafe-owned portable export workspaces.';

    public function handle(PortableExportWorkspaceService $workspaces): int
    {
        $minimumAgeHours = filter_var($this->option('min-age-hours'), FILTER_VALIDATE_INT);
        if ($minimumAgeHours === false || $minimumAgeHours < 1) {
            $this->error('min-age-hours must be an integer of at least 1.');

            return self::FAILURE;
        }

        try {
            $counts = $workspaces->cleanupStale(
                $minimumAgeHours,
                (bool) $this->option('apply'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $mode = $this->option('apply') ? 'apply' : 'dry-run';
        $this->line(sprintf(
            'mode=%s scanned=%d owned=%d stale=%d deleted=%d skipped=%d errors=%d',
            $mode,
            $counts['scanned'],
            $counts['owned'],
            $counts['stale'],
            $counts['deleted'],
            $counts['skipped'],
            $counts['errors'],
        ));

        return $counts['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
