<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use App\Services\LanguageService;
use Illuminate\Http\Request;

class MobileBootstrapController extends Controller
{
    public function __construct(private LanguageService $languageService)
    {
    }

    public function show(Request $request)
    {
        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $user = $request->user();
        $currentLanguage = $this->languageService->ensureEnglishMainlineSelection($user);

        return MobileApiResponse::success([
            'user' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'current_language' => $currentLanguage,
            'api_version' => 'v1',
            'schema_version' => MobileApiResponse::SCHEMA_VERSION,
            'device' => [
                'device_uuid' => $device->device_uuid,
                'platform' => $device->platform,
                'device_name' => $device->device_name,
                'app_version' => $device->app_version,
                'last_active_at' => $device->last_active_at?->toIso8601String(),
            ],
            'capabilities' => [
                'formal_sense_review' => true,
                'device_revocation' => true,
                'idempotent_mutations' => true,
                'operation_ledger' => true,
                'operation_undo_redo' => true,
                'review_control_manual_operations' => true,
                'review_control_preview' => true,
                'unified_read_only_search' => true,
                'offline_queue' => true,
                'article_packages' => true,
                'review_packages' => true,
                'connected_reader' => true,
                'local_dictionary_lookup' => true,
                'manual_word_sense_creation' => true,
                'daily_summary' => true,
                'reading_sessions' => true,
            ],
            'readiness' => [
                // Authentication and device lookup already proved the
                // authoritative database is reachable for this request.
                'database' => true,
                'selected_language' => $currentLanguage === 'english',
            ],
        ]);
    }
}
