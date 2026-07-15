<?php

namespace App\Services\Settings;

use App\Exceptions\QueueOrderValidationException;
use App\Models\Setting;
use App\Services\ReviewQueueOrderOptions;

class FsrsQueueOrderSettingsService
{
    private const KEY_MAP = [
        'interday_learning_review_order' => 'fsrs_queue_interday_learning_review_order',
        'new_review_order' => 'fsrs_queue_new_review_order',
        'review_sort_order' => 'fsrs_queue_review_sort_order',
        'new_sort_order' => 'fsrs_queue_new_sort_order',
    ];

    public function __construct(private SettingValueService $settingValues)
    {
    }

    public function get(): array
    {
        return $this->loadOptions()->toArray();
    }

    public function update(array $input): array
    {
        $allowedValues = [
            'interday_learning_review_order' => ReviewQueueOrderOptions::ALLOWED_INTERDAY,
            'new_review_order' => ReviewQueueOrderOptions::ALLOWED_NEW_REVIEW,
            'review_sort_order' => ReviewQueueOrderOptions::ALLOWED_REVIEW_SORT,
            'new_sort_order' => ReviewQueueOrderOptions::ALLOWED_NEW_SORT,
        ];

        $errors = [];
        foreach ($allowedValues as $apiName => $allowed) {
            if (array_key_exists($apiName, $input) && !in_array($input[$apiName], $allowed, true)) {
                $errors[$apiName] = '此值不在允许的选项中。';
            }
        }

        if ($errors !== []) {
            throw new QueueOrderValidationException($errors);
        }

        foreach (self::KEY_MAP as $apiName => $settingName) {
            if (array_key_exists($apiName, $input)) {
                $this->settingValues->upsertGlobal($settingName, $input[$apiName]);
            }
        }

        return $this->get();
    }

    private function loadOptions(): ReviewQueueOrderOptions
    {
        $rows = Setting::where('user_id', -1)
            ->whereIn('name', array_values(self::KEY_MAP))
            ->get()
            ->keyBy('name');

        $data = [];
        foreach (self::KEY_MAP as $apiName => $settingName) {
            $row = $rows->get($settingName);
            if (!$row) {
                continue;
            }

            $decoded = json_decode($row->value, true);
            $data[$apiName] = is_string($decoded) ? $decoded : $row->value;
        }

        try {
            return ReviewQueueOrderOptions::fromArray($data);
        } catch (\InvalidArgumentException) {
            return ReviewQueueOrderOptions::defaults();
        }
    }
}
