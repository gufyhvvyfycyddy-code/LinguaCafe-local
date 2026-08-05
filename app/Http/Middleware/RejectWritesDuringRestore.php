<?php

namespace App\Http\Middleware;

use App\Services\RestoreWriteFence;
use Closure;
use Illuminate\Http\Request;

class RejectWritesDuringRestore
{
    public function __construct(
        private RestoreWriteFence $fence,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethodSafe() && $this->fence->active()) {
            return response()->json([
                'error' => [
                    'code' => 'RESTORE_WRITE_FENCE_ACTIVE',
                    'message' => 'Writes are temporarily unavailable while database recovery is running.',
                ],
            ], 503);
        }

        return $next($request);
    }
}
