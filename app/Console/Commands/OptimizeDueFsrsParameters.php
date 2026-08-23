<?php

namespace App\Console\Commands;

use App\Models\ReviewSettingPresetBinding;
use App\Services\Settings\FsrsOptimizationSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class OptimizeDueFsrsParameters extends Command
{
    protected $signature = 'fsrs:optimize-due';

    protected $description = 'Optimizes due English FSRS presets using each user policy.';

    public function handle(FsrsOptimizationSettingsService $optimizer): int
    {
        $scanned = 0;
        $applied = 0;
        $failed = 0;

        ReviewSettingPresetBinding::query()
            ->where('language_id', 'english')
            ->orderBy('id')
            ->chunkById(100, function ($bindings) use ($optimizer, &$scanned, &$applied, &$failed): void {
                foreach ($bindings as $binding) {
                    $scanned++;
                    try {
                        $result = $optimizer->applyAutomaticallyIfDue(
                            (int) $binding->user_id,
                            (string) $binding->language_id,
                        );
                    } catch (\Throwable $exception) {
                        $failed++;
                        Log::error('Automatic FSRS optimization threw an exception.', [
                            'exception' => get_class($exception),
                            'user_id' => (int) $binding->user_id,
                            'language_id' => (string) $binding->language_id,
                        ]);
                        continue;
                    }

                    if ($result['applied']) {
                        $applied++;
                    } elseif ($result['attempted']) {
                        $failed++;
                        Log::warning('Automatic FSRS optimization attempt failed.', [
                            'user_id' => (int) $binding->user_id,
                            'language_id' => (string) $binding->language_id,
                            'error_code' => $result['error_code'] ?? null,
                        ]);
                    }
                }
            });

        $this->info("FSRS automatic optimization: scanned={$scanned} applied={$applied} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
