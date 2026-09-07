<?php

namespace App\Http\Middleware;

use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveMobileDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->can('mobile')) {
            return MobileApiResponse::error(
                'DEVICE_REVOKED',
                'This mobile device is not active.',
                401,
            );
        }

        $device = MobileDevice::where('user_id', $request->user()->id)
            ->where('personal_access_token_id', $token->id)
            ->whereNull('revoked_at')
            ->first();

        if (! $device) {
            return MobileApiResponse::error(
                'DEVICE_REVOKED',
                'This mobile device is not active.',
                401,
            );
        }

        if (! $request->isMethod('HEAD')) {
            $device->forceFill(['last_active_at' => now()])->save();
        }
        $request->attributes->set('mobile_device', $device);

        return $next($request);
    }
}
