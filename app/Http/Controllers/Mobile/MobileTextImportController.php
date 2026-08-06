<?php

namespace App\Http\Controllers\Mobile;

use App\Exceptions\MobileIdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileApiResponse;
use App\Models\MobileDevice;
use App\Services\ImportService;
use App\Services\MobileIdempotencyService;
use Illuminate\Http\Request;
use Throwable;

class MobileTextImportController extends Controller
{
    public function __construct(
        private MobileIdempotencyService $idempotency,
        private ImportService $imports,
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_action_id' => ['required', 'uuid'],
            'file_name' => ['required', 'string', 'max:255', 'regex:/\.txt$/i'],
            'content' => ['required', 'string', 'max:200000'],
            'book_name' => ['required', 'string', 'max:255'],
            'chapter_name' => ['required', 'string', 'max:255'],
        ]);
        $user = $request->user();
        if ($user->selected_language !== 'english') {
            return MobileApiResponse::error(
                'ENGLISH_LANGUAGE_REQUIRED',
                'Mobile text import is available only for the English learning language.',
                409,
            );
        }

        /** @var MobileDevice $device */
        $device = $request->attributes->get('mobile_device');
        $requestPayload = [
            'file_name' => $validated['file_name'],
            'content' => $validated['content'],
            'book_name' => $validated['book_name'],
            'chapter_name' => $validated['chapter_name'],
        ];

        try {
            $result = $this->idempotency->execute(
                $user->id,
                $device,
                'library.text_import',
                $validated['client_action_id'],
                $requestPayload,
                function (string $operationId) use ($user, $validated) {
                    $processingMode = $this->imports->importText(
                        $user->id,
                        $user->uuid,
                        3000,
                        'detailed',
                        $validated['content'],
                        -1,
                        $validated['book_name'],
                        $validated['chapter_name'],
                    );

                    return [
                        'status' => 201,
                        'body' => [
                            'operation_id' => $operationId,
                            'client_action_id' => $validated['client_action_id'],
                            'file_name' => $validated['file_name'],
                            'book_name' => $validated['book_name'],
                            'chapter_name' => $validated['chapter_name'],
                            'processing_mode' => $processingMode,
                        ],
                    ];
                },
            );
        } catch (MobileIdempotencyConflictException) {
            return MobileApiResponse::error(
                'IDEMPOTENCY_KEY_REUSED',
                'The client action id was already used with a different request.',
                409,
            );
        } catch (Throwable $exception) {
            report($exception);
            return MobileApiResponse::error(
                'TEXT_IMPORT_FAILED',
                'The document could not be imported.',
                500,
            );
        }

        $body = $result['body'];
        $body['replayed'] = $result['replayed'];

        return MobileApiResponse::success($body, $result['status']);
    }
}
