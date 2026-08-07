<?php

namespace App\Http\Controllers;

use App\Models\WordSense;
use App\Services\MediaAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class MediaController extends Controller
{
    public function __construct(private MediaAssetService $media) {}

    public function store(Request $request, WordSense $wordSense): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            (int) $wordSense->user_id === (int) $user->id
            && hash_equals((string) $wordSense->language_id, (string) $user->selected_language),
            404,
        );
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'role' => ['required', 'in:word_pronunciation,example_audio'],
            'sentence' => ['nullable', 'string', 'max:5000'],
            'copyright_status' => ['required', 'in:owned,licensed,public_domain,unknown'],
            'copyright_source' => ['nullable', 'string', 'max:512'],
        ]);

        return response()->json([
            'media' => $this->media->attach(
                $wordSense,
                $validated['file'],
                $validated['role'],
                $validated['sentence'] ?? null,
                $validated['copyright_status'],
                $validated['copyright_source'] ?? null,
            ),
        ], 201);
    }

    public function destroy(Request $request, string $referenceId): JsonResponse
    {
        $user = $request->user();
        $this->media->remove($referenceId, $user->id, $user->selected_language);
        return response()->json(['removed' => true, 'retention_days' => (int) config('media.retention_days')]);
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

    public function check(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json($this->media->check($user->id, $user->selected_language));
    }
}
