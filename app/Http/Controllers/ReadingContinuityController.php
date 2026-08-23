<?php

namespace App\Http\Controllers;

use App\Services\ReadingContinuityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingContinuityController extends Controller
{
    public function __construct(private ReadingContinuityService $service)
    {
    }

    public function show(int $chapter)
    {
        try {
            return response()->json($this->service->current(
                Auth::user()->id,
                Auth::user()->selected_language,
                $chapter,
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->contractError($e);
        }
    }

    public function update(int $chapter, Request $request)
    {
        $data = $request->validate([
            'source_revision' => ['required', 'string', 'max:80'],
            'canonical_token_index' => ['required', 'integer', 'min:0'],
        ]);

        try {
            return response()->json($this->service->saveWebPosition(
                Auth::user()->id,
                Auth::user()->selected_language,
                $chapter,
                $data['source_revision'],
                (int) $data['canonical_token_index'],
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->contractError($e);
        }
    }

    private function contractError(\InvalidArgumentException $exception)
    {
        $code = $exception->getMessage();
        $statuses = [
            ReadingContinuityService::ERROR_CHAPTER_NOT_FOUND => 404,
            ReadingContinuityService::ERROR_STALE_SOURCE => 409,
            ReadingContinuityService::ERROR_INVALID_TOKEN => 422,
        ];
        if (!isset($statuses[$code])) {
            $code = 'READING_CONTINUITY_CONFLICT';
            $statuses[$code] = 409;
        }

        return response()->json([
            'success' => false,
            'error_code' => $code,
            'message' => 'Reading progress conflicts with the current server state.',
        ], $statuses[$code]);
    }
}
