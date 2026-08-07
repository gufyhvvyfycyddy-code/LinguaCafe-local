<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Services\MediaAssetService;
use App\Services\MediaManifestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MobileMediaController extends Controller
{
    public function __construct(
        private MediaAssetService $media,
        private MediaManifestService $manifest,
    ) {}

    public function index(Request $request)
    {
        $validator = Validator::make($request->only('sense_ids'), [
            'sense_ids' => ['required', 'array', 'max:100'],
            'sense_ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return MobileApiResponse::error(
                'VALIDATION_ERROR',
                'The media manifest request is invalid.',
                422,
                $validator->errors()->toArray(),
            );
        }
        $validated = $validator->validated();
        $user = $request->user();
        return MobileApiResponse::success([
            'items_by_sense' => $this->manifest->forSenseIds(
                $user->id,
                $user->selected_language,
                $validated['sense_ids'],
            ),
        ]);
    }

    public function download(Request $request, string $assetId): BinaryFileResponse
    {
        $user = $request->user();
        $file = $this->media->resolveDownload($assetId, $user->id, $user->selected_language);
        $asset = $file['asset'];
        $response = response()->file($file['path'], [
            'Content-Type' => $asset->mime_type,
            'ETag' => '"' . $asset->sha256 . '"',
            'X-Content-SHA256' => $asset->sha256,
        ])->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $asset->original_name);
        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->setImmutable();

        if (! $request->isMethod('HEAD')) {
            $this->media->recordDownloadAccess($asset);
        }

        return $response;
    }
}
