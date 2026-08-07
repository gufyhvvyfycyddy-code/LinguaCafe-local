<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class GoalIdentityConcurrencyTest extends TestCase
{
    public function test_two_connections_cannot_create_the_same_goal_identity(): void
    {
        $userId = 1_900_000_000 + random_int(1, 50_000_000);

        try {
            [$firstProcess, $firstPipes] = $this->startInsertProcess($userId);
            [$secondProcess, $secondPipes] = $this->startInsertProcess($userId);

            foreach ([$firstPipes, $secondPipes] as $pipes) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }

            $outcomes = [];
            foreach ([[$firstProcess, $firstPipes], [$secondProcess, $secondPipes]] as [$process, $pipes]) {
                $stdout = trim(stream_get_contents($pipes[1]));
                $stderr = trim(stream_get_contents($pipes[2]));
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                $this->assertSame(
                    0,
                    $exitCode,
                    "Concurrent Goal insert failed.\nSTDOUT: {$stdout}\nSTDERR: {$stderr}",
                );
                $outcomes[] = $stdout;
            }

            sort($outcomes);
            $this->assertSame(['duplicate', 'inserted'], $outcomes);
            $this->assertSame(
                1,
                DB::table('goals')
                    ->where('user_id', $userId)
                    ->where('language', 'english')
                    ->where('type', 'review')
                    ->count(),
            );
        } finally {
            DB::table('goals')->where('user_id', $userId)->delete();
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startInsertProcess(int $userId): array
    {
        $code = <<<'PHP'
$basePath = $argv[1];
$userId = (int) $argv[2];
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
fgets(STDIN);
try {
    Illuminate\Support\Facades\DB::table('goals')->insert([
        'name' => 'R11Z concurrent goal',
        'user_id' => $userId,
        'language' => 'english',
        'type' => 'review',
        'target_id' => null,
        'current_chapter' => null,
        'quantity' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    fwrite(STDOUT, 'inserted');
} catch (Illuminate\Database\QueryException $exception) {
    $isIdentityDuplicate = (int) ($exception->errorInfo[1] ?? 0) === 1062
        && str_contains(
            strtolower($exception->getMessage()),
            'goals_identity_fingerprint_unique',
        );
    if (! $isIdentityDuplicate) {
        throw $exception;
    }
    fwrite(STDOUT, 'duplicate');
}
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $code, base_path(), (string) $userId],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the concurrent Goal insert process.');
        }

        return [$process, $pipes];
    }
}
