<?php

namespace App\Console\Commands;

use App\Support\GoalIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditGoalIdentity extends Command
{
    protected $signature = 'goals:audit-identity {--json : Output stable machine-readable JSON}';

    protected $description = 'Read-only audit of Goal identity duplicates, drift, and dangling achievements.';

    public function handle(): int
    {
        try {
            $counts = GoalIdentity::audit(DB::connection());
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'schema_version' => 'goal_identity_audit_v1',
                    'status' => 'error',
                    'error' => [
                        'code' => 'GOAL_IDENTITY_AUDIT_FAILED',
                        'message' => 'The Goal identity audit could not be completed.',
                    ],
                ], JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Goal identity audit failed.');
            }

            report($exception);

            return self::FAILURE;
        }

        $status = $counts['has_issues'] ? 'issues_found' : 'clean';
        $payload = [
            'schema_version' => 'goal_identity_audit_v1',
            'status' => $status,
            'counts' => $counts,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Goal identity audit: '.$status);
            foreach ($counts as $name => $value) {
                if ($name === 'type_counts' || $name === 'has_issues') {
                    continue;
                }

                $this->line(sprintf('  %s: %d', $name, $value));
            }
            $this->line('  type_counts: '.json_encode($counts['type_counts'], JSON_UNESCAPED_SLASHES));
        }

        return $counts['has_issues'] ? 2 : self::SUCCESS;
    }
}
