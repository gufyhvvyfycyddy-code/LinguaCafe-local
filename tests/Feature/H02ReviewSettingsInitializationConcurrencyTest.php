<?php

namespace Tests\Feature;

use App\Models\ReviewSettingPreset;
use App\Models\ReviewSettingPresetBinding;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class H02ReviewSettingsInitializationConcurrencyTest extends TestCase
{
    public function test_different_users_can_initialize_review_settings_concurrently_without_deadlock(): void
    {
        $users = collect(range(1, 8))->map(fn (int $index): User => User::forceCreate([
            'name' => "H02 Concurrent Preset {$index}",
            'email' => 'h02-preset-concurrency-'.Str::uuid().'@example.test',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'is_admin' => false,
            'uuid' => (string) Str::uuid(),
        ]));

        $workers = [];

        try {
            foreach ($users as $user) {
                $workers[] = $this->startResolverProcess((int) $user->id);
            }

            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }

            foreach ($workers as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                $this->assertSame(
                    0,
                    $exitCode,
                    "Concurrent review-settings initialization failed.\nSTDOUT: {$stdout}\nSTDERR: {$stderr}",
                );
            }

            foreach ($users as $user) {
                $this->assertSame(
                    1,
                    ReviewSettingPreset::query()
                        ->where('user_id', $user->id)
                        ->where('name', 'Default')
                        ->where('is_default', true)
                        ->count(),
                );
                $this->assertSame(
                    1,
                    ReviewSettingPresetBinding::query()
                        ->where('user_id', $user->id)
                        ->where('language_id', 'english')
                        ->count(),
                );
            }
        } finally {
            $userIds = $users->pluck('id')->all();
            ReviewSettingPresetBinding::query()->whereIn('user_id', $userIds)->delete();
            ReviewSettingPreset::query()->whereIn('user_id', $userIds)->delete();
            User::query()->whereIn('id', $userIds)->delete();
        }
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startResolverProcess(int $userId): array
    {
        $code = <<<'PHP'
$basePath = $argv[1];
$userId = (int) $argv[2];
require $basePath.'/tests/bootstrap.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
fgets(STDIN);
$app->make(App\Services\Settings\Presets\ReviewSettingsResolver::class)->resolve($userId, 'english');
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
            throw new RuntimeException('Could not start H-02 review-settings concurrency worker.');
        }

        return [$process, $pipes];
    }
}
