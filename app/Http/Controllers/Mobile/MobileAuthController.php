<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class MobileAuthController extends Controller
{
    public function storeToken(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_uuid' => ['required', 'uuid'],
            'platform' => ['required', 'in:android,ios,web'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'app_version' => ['required', 'string', 'max:50'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return MobileApiResponse::error(
                'INVALID_CREDENTIALS',
                'The supplied credentials are invalid.',
                401,
            );
        }

        [$plainTextToken, $device] = DB::transaction(function () use ($user, $validated) {
            MobileDevice::query()->insertOrIgnore([
                'user_id' => $user->id,
                'device_uuid' => $validated['device_uuid'],
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'],
                'last_active_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $device = MobileDevice::where('user_id', $user->id)
                ->where('device_uuid', $validated['device_uuid'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($device->personal_access_token_id !== null) {
                PersonalAccessToken::whereKey($device->personal_access_token_id)->delete();
            }

            $device->fill([
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'],
                'personal_access_token_id' => null,
                'last_active_at' => now(),
                'revoked_at' => null,
            ])->save();

            $tokenResult = $user->createToken(
                "mobile:{$device->device_uuid}",
                ['mobile'],
            );

            $device->forceFill([
                'personal_access_token_id' => $tokenResult->accessToken->id,
            ])->save();

            return [$tokenResult->plainTextToken, $device->fresh()];
        }, 3);

        return MobileApiResponse::success([
            'token' => $plainTextToken,
            'token_type' => 'Bearer',
            'device' => $this->serializeDevice($device),
        ], 201);
    }

    private function serializeDevice(MobileDevice $device): array
    {
        return [
            'device_uuid' => $device->device_uuid,
            'platform' => $device->platform,
            'device_name' => $device->device_name,
            'app_version' => $device->app_version,
            'last_active_at' => $device->last_active_at?->toIso8601String(),
            'revoked' => $device->revoked_at !== null,
        ];
    }
}
