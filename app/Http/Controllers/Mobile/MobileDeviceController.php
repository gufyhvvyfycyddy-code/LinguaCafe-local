<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class MobileDeviceController extends Controller
{
    public function destroy(string $deviceUuid, Request $request)
    {
        $device = MobileDevice::where('user_id', $request->user()->id)
            ->where('device_uuid', $deviceUuid)
            ->first();

        if (! $device) {
            return MobileApiResponse::error(
                'DEVICE_NOT_FOUND',
                'The mobile device does not exist.',
                404,
            );
        }

        DB::transaction(function () use ($device) {
            $lockedDevice = MobileDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();

            if ($lockedDevice->personal_access_token_id !== null) {
                PersonalAccessToken::whereKey($lockedDevice->personal_access_token_id)->delete();
            }

            $lockedDevice->forceFill([
                'personal_access_token_id' => null,
                'revoked_at' => $lockedDevice->revoked_at ?? now(),
            ])->save();
        });

        return MobileApiResponse::success([
            'device' => [
                'device_uuid' => $deviceUuid,
                'revoked' => true,
            ],
        ]);
    }
}
