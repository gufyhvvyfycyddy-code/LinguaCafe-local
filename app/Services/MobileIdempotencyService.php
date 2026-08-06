<?php

namespace App\Services;

use App\Exceptions\MobileIdempotencyConflictException;
use App\Models\MobileClientAction;
use App\Models\MobileDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MobileIdempotencyService
{
    /**
     * @param  callable(string): array{status: int, body: array}  $mutation
     * @return array{operation_id: string, status: int, body: array, replayed: bool}
     */
    public function execute(
        int $userId,
        MobileDevice $device,
        string $actionType,
        string $clientActionId,
        array $requestPayload,
        callable $mutation,
    ): array {
        $requestHash = hash('sha256', json_encode(
            $this->canonicalize($requestPayload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return DB::transaction(function () use (
            $userId,
            $device,
            $actionType,
            $clientActionId,
            $requestHash,
            $mutation,
        ) {
            MobileClientAction::query()->insertOrIgnore([
                'operation_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'mobile_device_id' => $device->id,
                'action_type' => $actionType,
                'client_action_id' => $clientActionId,
                'request_hash' => $requestHash,
                'status' => MobileClientAction::STATUS_PROCESSING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $action = MobileClientAction::where('user_id', $userId)
                ->where('mobile_device_id', $device->id)
                ->where('action_type', $actionType)
                ->where('client_action_id', $clientActionId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! hash_equals($action->request_hash, $requestHash)) {
                throw new MobileIdempotencyConflictException($action);
            }

            if ($action->status === MobileClientAction::STATUS_COMPLETED) {
                return [
                    'operation_id' => $action->operation_id,
                    'status' => $action->response_status,
                    'body' => $action->response_body,
                    'replayed' => true,
                ];
            }

            if ($action->status !== MobileClientAction::STATUS_PROCESSING) {
                throw new RuntimeException('Unsupported mobile client action state.');
            }

            $result = $mutation($action->operation_id);
            $action->forceFill([
                'status' => MobileClientAction::STATUS_COMPLETED,
                'response_status' => $result['status'],
                'response_body' => $result['body'],
            ])->save();

            return [
                'operation_id' => $action->operation_id,
                'status' => $result['status'],
                'body' => $result['body'],
                'replayed' => false,
            ];
        }, 3);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
