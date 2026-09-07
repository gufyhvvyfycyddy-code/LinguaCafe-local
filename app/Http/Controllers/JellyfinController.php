<?php

namespace App\Http\Controllers;

use App\Services\JellyfinService;

class JellyfinController extends Controller
{
    private $jellyfinService;

    function __construct(JellyfinService $jellyfinService) {
        $this->jellyfinService = $jellyfinService;
    }

    public function getJellyfinCurrentlyPlayedSubtitles () {
        try {
            $subtitles = $this->jellyfinService->getJellyfinCurrentlyPlayedSubtitles();
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'error' => [
                    'code' => 'JELLYFIN_UNAVAILABLE',
                    'message' => 'Jellyfin is temporarily unavailable.',
                ],
            ], 503);
        }

        return response()->json($subtitles, 200);
    }
}
