<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LanguageSelectionConcurrencyTest extends TestCase
{
    public function test_two_processes_converge_same_legacy_user_to_one_english_goal_set(): void
    {
        $user = User::forceCreate([
            'name' => 'Concurrent Language User',
            'email' => 'language-concurrency-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'french',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);
        Goal::forceCreate([
            'user_id' => $user->id,
            'language' => 'french',
            'name' => 'Reading',
            'type' => 'read_words',
            'quantity' => 71,
        ]);

        try {
            [$firstProcess, $firstPipes] = $this->startEnglishConvergenceProcess($user->id);
            [$secondProcess, $secondPipes] = $this->startEnglishConvergenceProcess($user->id);

            foreach ([$firstPipes, $secondPipes] as $pipes) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }

            $results = [];
            foreach ([[$firstProcess, $firstPipes], [$secondProcess, $secondPipes]] as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $results[] = [
                    'exit_code' => proc_close($process),
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ];
            }

            foreach ($results as $result) {
                $this->assertSame(
                    0,
                    $result['exit_code'],
                    "Concurrent English convergence failed.\nSTDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}",
                );
            }

            $this->assertSame('english', $user->refresh()->selected_language);
            $this->assertSame(
                ['learn_words', 'read_words', 'review'],
                Goal::query()
                    ->where('user_id', $user->id)
                    ->where('language', 'english')
                    ->orderBy('type')
                    ->pluck('type')
                    ->all(),
            );
            $this->assertSame(
                3,
                Goal::query()
                    ->where('user_id', $user->id)
                    ->where('language', 'english')
                    ->count(),
            );
            $this->assertDatabaseHas('goals', [
                'user_id' => $user->id,
                'language' => 'french',
                'type' => 'read_words',
                'quantity' => 71,
            ]);
        } finally {
            Goal::query()->where('user_id', $user->id)->delete();
            $user->delete();
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startEnglishConvergenceProcess(int $userId): array
    {
        $code = <<<'PHP'
$basePath = $argv[1];
$userId = (int) $argv[2];
require $basePath.'/tests/bootstrap.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
fgets(STDIN);
$user = App\Models\User::query()->findOrFail($userId);
$app->make(App\Services\LanguageService::class)->ensureEnglishMainlineSelection($user);
PHP;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, '-r', $code, base_path(), (string) $userId],
            $descriptors,
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the concurrent English convergence process.');
        }

        return [$process, $pipes];
    }
}
