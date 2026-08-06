<?php

namespace App\Http\Controllers;

use App\Models\ReviewCard;
use App\Services\AnkiWordSensePackageService;
use App\Services\InvalidBrowserSearchException;
use App\Services\PortableDataService;
use App\Services\ReviewCardExportService;
use App\Services\ReviewCardManageFilterState;
use App\Services\ReviewCardManageItemSerializerService;
use App\Services\ReviewCardManageQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortableDataController extends Controller
{
    public function __construct(
        private ReviewCardManageQueryService $queryService,
        private ReviewCardManageItemSerializerService $serializer,
        private AnkiWordSensePackageService $anki,
        private PortableDataService $portable,
    ) {}

    public function exportAnki(Request $request): StreamedResponse|JsonResponse
    {
        $items = $this->items($request);
        if ($items instanceof JsonResponse) {
            return $items;
        }
        $package = $this->anki->build(
            $items,
            $request->boolean('include_scheduling'),
            hash('sha256', (string) Auth::user()->uuid),
        );
        return response()->streamDownload(function () use ($package) {
            try {
                readfile($package['path']);
            } finally {
                $this->anki->cleanupPackage($package['path']);
            }
        }, 'linguacafe-wordsenses-' . now()->format('Ymd-His') . '.apkg', [
            'Content-Type' => 'application/octet-stream',
            'X-Export-Count' => (string) $package['count'],
            'X-Content-SHA256' => $package['sha256'],
        ]);
    }

    public function exportContentJson(Request $request): JsonResponse
    {
        $items = $this->items($request);
        if ($items instanceof JsonResponse) {
            return $items;
        }
        $user = Auth::user();
        return response()->json(
            $this->portable->contentEnvelope(
                $items->all(),
                $user->id,
                $user->selected_language,
                $request->boolean('include_scheduling'),
            ),
            200,
            ['Content-Disposition' => 'attachment; filename="linguacafe-wordsenses-' . now()->format('Ymd-His') . '.json"'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }

    public function exportContentCsv(Request $request): StreamedResponse|JsonResponse
    {
        $items = $this->items($request);
        if ($items instanceof JsonResponse) {
            return $items;
        }
        $user = Auth::user();
        $envelope = $this->portable->contentEnvelope(
            $items->all(),
            $user->id,
            $user->selected_language,
            $request->boolean('include_scheduling'),
        );
        return response()->streamDownload(function () use ($envelope) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, PortableDataService::CONTENT_FIELDS);
            foreach ($envelope['items'] as $item) {
                $row = [];
                foreach (PortableDataService::CONTENT_FIELDS as $field) {
                    $value = $item[$field] ?? '';
                    $row[] = is_array($value) ? implode(', ', $value) : $value;
                }
                fputcsv($stream, $row);
            }
            fclose($stream);
        }, 'linguacafe-wordsenses-' . now()->format('Ymd-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Count' => (string) count($envelope['items']),
        ]);
    }

    public function exportFullPackage(Request $request): StreamedResponse|JsonResponse
    {
        $items = $this->allSenseItems();
        if ($items instanceof JsonResponse) {
            return $items;
        }
        $user = Auth::user();
        $package = $this->portable->buildFullPackage(
            $items->all(),
            $user->id,
            $user->selected_language,
            $request->boolean('include_media'),
        );
        return response()->streamDownload(function () use ($package) {
            try {
                readfile($package['path']);
            } finally {
                $this->portable->cleanupPackage($package['path']);
            }
        }, 'linguacafe-portable-' . now()->format('Ymd-His') . '.lcpkg', [
            'Content-Type' => 'application/zip',
            'X-Export-Count' => (string) $package['count'],
            'X-Content-SHA256' => $package['sha256'],
            'X-Media-Count' => (string) $package['media_count'],
        ]);
    }

    public function previewImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:25600', 'mimes:json,csv,zip', 'extensions:json,csv,apkg,lcpkg'],
        ]);
        $user = Auth::user();
        return response()->json($this->portable->preview(
            $validated['file'],
            $user->id,
            $user->selected_language,
        ));
    }

    public function applyImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preview_token' => ['required', 'uuid'],
            'confirm' => ['required', 'accepted'],
        ]);
        $user = Auth::user();
        return response()->json($this->portable->apply(
            $validated['preview_token'],
            $user->id,
            $user->selected_language,
        ));
    }

    private function items(Request $request)
    {
        $user = Auth::user();
        try {
            $state = ReviewCardManageFilterState::fromRequest($request);
            $criteria = $this->queryService->parseCriteriaForState($state);
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Invalid review card filters.', 'errors' => $exception->errors()], 422);
        } catch (InvalidBrowserSearchException $exception) {
            return response()->json($exception->toResponseArray(), 422);
        }
        $query = $this->queryService->buildFromFilterState($state, $criteria, $user->id, $user->selected_language);
        $total = $query->count();
        if ($total > ReviewCardExportService::EXPORT_LIMIT) {
            return response()->json([
                'message' => '当前筛选结果超过 ' . ReviewCardExportService::EXPORT_LIMIT . ' 条，请缩小范围。',
                'total' => $total,
                'limit' => ReviewCardExportService::EXPORT_LIMIT,
            ], 422);
        }
        return $this->serializer->buildItems($query->get(), $user->id, $user->selected_language);
    }

    private function allSenseItems()
    {
        $user = Auth::user();
        $query = ReviewCard::query()
            ->where('user_id', $user->id)
            ->where('language_id', $user->selected_language)
            ->where('target_type', ReviewCard::TARGET_SENSE)
            ->orderBy('id');
        $total = $query->count();
        if ($total > ReviewCardExportService::EXPORT_LIMIT) {
            return response()->json([
                'message' => 'Sense Card 总数超过 ' . ReviewCardExportService::EXPORT_LIMIT . ' 条，V1 全量包暂不支持。',
                'total' => $total,
                'limit' => ReviewCardExportService::EXPORT_LIMIT,
            ], 422);
        }
        return $this->serializer->buildItems(
            $query->get(),
            $user->id,
            $user->selected_language,
        );
    }
}
