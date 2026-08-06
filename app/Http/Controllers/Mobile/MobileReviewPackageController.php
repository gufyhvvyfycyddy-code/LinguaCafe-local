<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\InvalidMobilePackageCursorException;
use App\Services\InvalidMobilePackageSourceException;
use App\Services\MobileReviewPackageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileReviewPackageController extends Controller
{
    public function __construct(private MobileReviewPackageService $service)
    {
    }

    public function shortTerm(Request $request)
    {
        $validator = Validator::make(
            $request->only(['horizon_days', 'limit', 'cursor']),
            [
                'horizon_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
                'limit' => [
                    'sometimes',
                    'integer',
                    'min:1',
                    'max:' . MobileReviewPackageService::MAX_LIMIT,
                ],
                'cursor' => ['sometimes', 'nullable', 'string', 'max:8192'],
            ],
        );
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The short-term review package request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }

        $user = $request->user();

        try {
            $payload = $this->service->build(
                $user->id,
                $user->selected_language,
                (int) $request->input(
                    'horizon_days',
                    MobileReviewPackageService::DEFAULT_HORIZON_DAYS,
                ),
                (int) $request->input('limit', MobileReviewPackageService::DEFAULT_LIMIT),
                $request->input('cursor'),
            );
        } catch (InvalidMobilePackageCursorException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
            );
        } catch (InvalidMobilePackageSourceException $exception) {
            return MobileApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
            );
        }

        return MobileApiResponse::success($payload);
    }
}
