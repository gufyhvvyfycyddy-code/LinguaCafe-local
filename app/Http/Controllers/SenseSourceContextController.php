<?php

namespace App\Http\Controllers;

use App\Services\SenseSourceContextService;
use Illuminate\Support\Facades\Auth;

class SenseSourceContextController extends Controller
{
    public function __construct(
        private SenseSourceContextService $senseSourceContextService,
    )
    {
    }

    public function sourceContext(int $id)
    {
        return response()->json($this->senseSourceContextService->sourceContext(
            Auth::user()->id,
            Auth::user()->selected_language,
            $id,
        ));
    }

    /**
     * Multi-source variant of sourceContext: returns a list of distinct
     * chapter-based source contexts (up to 3) for the review page source
     * dialog carousel. Falls back to a single-entry list when no
     * chapter-based sources are available.
     *
     * SenseSourceContextFollowDisplayedOccurrence-1000-7:
     * Accepts an optional ?preferred_occurrence_id= query parameter. When
     * supplied, the service attempts to place that occurrence's source
     * context at sources[0] so the source dialog opens on the example the
     * user is currently looking at on the review card. The id is strictly
     * validated server-side (owner / language / sense / status=bound);
     * on any failure the call silently falls back to the original
     * multi-source list and reports the outcome via
     * preferred_occurrence_status in the JSON payload.
     */
    public function sourceContextList(int $id)
    {
        $preferred = request()->query('preferred_occurrence_id');
        $preferredId = $preferred !== null ? (int) $preferred : null;

        return response()->json($this->senseSourceContextService->sourceContextList(
            Auth::user()->id,
            Auth::user()->selected_language,
            $id,
            $preferredId,
        ));
    }
}
