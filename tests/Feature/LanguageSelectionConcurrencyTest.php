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
    public function test_two_processes_switching_the_same_user_create_only_three_default_goals(): void
    {
        $user = User::forceCreate([
            'name' => 'Concurrent Language User',
            'email' => 'language-concurrency-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]);

        try {
            [$firstProcess, $firstPipes] = $this->startLanguageSelectionProcess($user->id);
            [$secondProcess, $secondPipes] = $this->startLanguageSelectionProcess($user->id);

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
                    "Concurrent language selection failed.\nSTDOUT: {$result['stdout']}\nSTDERR: {$result['stderr']}",
                );
            }

            $this->assertSame('french', $user->refresh()->selected_language);
            $this->assertSame(
                ['learn_words', 'read_words', 'review'],
                Goal::query()
                    ->where('user_id', $user->id)
                    ->where('language', 'french')
                    ->orderBy('type')
                    ->pluck('type')
                    ->all(),
            );
            $this->assertSame(
                3,
                Goal::query()
                    ->where('user_id', $user->id)
                    ->where('language', 'french')
                    ->count(),
            );
        } finally {
            Goal::query()->where('user_id', $user->id)->delete();
            $user->delete();
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startLanguageSelectionProcess(int $userId): array
    {
        $code = <<<'PHP'
$basePath = $argv[1];
$userId = (int) $argv[2];
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
fgets(STDIN);
$user = App\Models\User::query()->findOrFail($userId);
$app->make(App\Services\LanguageService::class)->selectLanguage($user, 'french');
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
            throw new RuntimeException('Could not start the concurrent language selection process.');
        }

        return [$process, $pipes];
    }
}
