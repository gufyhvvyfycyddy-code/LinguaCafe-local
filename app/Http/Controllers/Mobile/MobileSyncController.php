<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use App\Services\MobileQueuedActionSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileSyncController extends Controller
{
    public function __construct(private MobileQueuedActionSyncService $syncService)
    {
    }

    public function store(Request $request)
    {
        if (strlen($request->getContent()) > MobileQueuedActionSyncService::MAX_REQUEST_BYTES) {
            return MobileApiResponse::error(
                'PAYLOAD_TOO_LARGE',
                'The queued action batch exceeds the payload limit.',
                413,
            );
        }

        $raw = json_decode($request->getContent(), true);
        $actionsAreList = is_array($raw)
            && isset($raw['actions'])
            && is_array($raw['actions'])
            && array_is_list($raw['actions']);
        $validator = Validator::make($request->all(), [
            'batch_id' => ['required', 'uuid'],
            'actions' => [
                'required',
                'array',
                'min:1',
                'max:' . MobileQueuedActionSyncService::MAX_ACTIONS,
                function (string $attribute, mixed $value, \Closure $fail) use ($actionsAreList) {
                    if (!$actionsAreList) {
                        $fail('The actions field must be a JSON list.');
                    }
                },
            ],
            'actions.*' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The queued action batch is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $user = $request->user();
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');

        return MobileApiResponse::success($this->syncService->sync(
            $user->id,
            $user->selected_language,
            $device,
            $request->input('batch_id'),
            $request->input('actions'),
        ));
    }
}
