<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\InvalidBrowserSearchException;
use App\Services\ReviewCardManageFilterState;
use App\Services\ReviewCardManageItemSerializerService;
use App\Services\ReviewCardManageQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MobileReviewCardSearchController extends Controller
{
    public function __construct(
        private ReviewCardManageQueryService $queryService,
        private ReviewCardManageItemSerializerService $itemSerializer,
    ) {
    }

    public function index(Request $request)
    {
        $pageValidation = Validator::make($request->only(['page', 'per_page']), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($pageValidation->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The search request is invalid.',
                422,
                $pageValidation->errors()->toArray(),
            );
        }

        try {
            $state = ReviewCardManageFilterState::fromRequest($request);
            $criteria = $this->queryService->parseCriteriaForState($state);
        } catch (ValidationException $exception) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The search criteria are invalid.',
                422,
                $exception->errors(),
            );
        } catch (InvalidBrowserSearchException $exception) {
            return MobileApiResponse::error(
                'INVALID_SEARCH',
                'The search grammar is invalid.',
                422,
                $exception->toResponseArray(),
            );
        }

        $user = $request->user();
        $perPage = (int) $request->input('per_page', 20);
        $query = $this->queryService->buildFromFilterState(
            $state,
            $criteria,
            $user->id,
            $user->selected_language,
        );
        $paginator = $query->paginate($perPage);

        return MobileApiResponse::success([
            'items' => $this->itemSerializer->buildItems(
                $paginator->getCollection(),
                $user->id,
                $user->selected_language,
            ),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'criteria' => $state->toArray(),
            'criteria_version' => 2,
            'search_meta' => $criteria->toSearchMeta(),
        ]);
    }
}
