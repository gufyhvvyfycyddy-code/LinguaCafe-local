<?php

namespace App\Services\Settings;

use App\Exceptions\AdvancedReviewSettingsValidationException;
use App\Services\Settings\Presets\ReviewSettingsResolver;

class AdvancedReviewSettingsService
{
    private const ALLOWED_SECTIONS = ['scheduling', 'experience'];

    public function __construct(private ReviewSettingsResolver $reviewSettings)
    {
    }

    public function get(int $userId, string $language): array
    {
        return array_merge(
            ['success' => true],
            $this->reviewSettings->resolve($userId, $language)->advancedSettingsForApi(),
            ['preset' => $this->reviewSettings->metadata($userId, $language)],
        );
    }

    public function update(int $userId, string $language, array $input): array
    {
        $unknown = array_diff(array_keys($input), self::ALLOWED_SECTIONS);
        if ($unknown !== []) {
            throw new AdvancedReviewSettingsValidationException([
                'settings' => '包含不支持的设置区域：' . implode(', ', $unknown),
            ]);
        }

        $patch = [];
        foreach (self::ALLOWED_SECTIONS as $section) {
            if (!array_key_exists($section, $input)) {
                continue;
            }
            if (!is_array($input[$section])) {
                throw new AdvancedReviewSettingsValidationException([
                    $section => "{$section} 必须是对象。",
                ]);
            }
            $patch[$section] = $input[$section];
        }
        if ($patch === []) {
            throw new AdvancedReviewSettingsValidationException([
                'settings' => '至少提交一个设置区域。',
            ]);
        }

        try {
            $config = $this->reviewSettings->resolve($userId, $language)->withPatch($patch);
        } catch (\InvalidArgumentException $exception) {
            throw new AdvancedReviewSettingsValidationException([
                'settings' => $exception->getMessage(),
            ]);
        }

        $saved = $this->reviewSettings->mutate($userId, $language, [
            'scheduling' => $config->scheduling(),
            'experience' => $config->experience(),
        ]);

        return array_merge(
            ['success' => true, 'message' => '高级复习设置已保存，只影响之后计算的到期时间。'],
            $saved->advancedSettingsForApi(),
            ['preset' => $this->reviewSettings->metadata($userId, $language)],
        );
    }
}
