<?php

namespace App\Http\Controllers;

use App\Exceptions\BackupException;
use App\Services\BackupRestoreService;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BackupController extends Controller
{
    public function index(BackupService $backups)
    {
        return response()->json([
            'backups' => $backups->listBackups(),
        ]);
    }

    public function store(BackupService $backups)
    {
        try {
            $backup = $backups->createBackup();
        } catch (BackupException $exception) {
            return response()->json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->httpStatus);
        }

        return response()->json([
            'backup' => $backup,
        ], 201);
    }

    public function restore(
        string $backupId,
        Request $request,
        BackupRestoreService $restore,
    ) {
        $validator = Validator::make($request->all(), [
            'confirmation' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(new BackupException(
                'BACKUP_RESTORE_REQUEST_INVALID',
                'The restore request is invalid.',
                422,
            ));
        }

        try {
            $result = $restore->confirm(
                $backupId,
                (int) $request->user()->id,
                $request->string('confirmation')->toString(),
            );
        } catch (BackupException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'restore_operation' => $result,
        ], 202);
    }

    public function restoreStatus(
        string $operationId,
        Request $request,
        BackupRestoreService $restore,
    ) {
        try {
            $result = $restore->status(
                $operationId,
                (int) $request->user()->id,
            );
        } catch (BackupException $exception) {
            return $this->errorResponse($exception);
        }

        return response()->json([
            'restore_operation' => $result,
        ]);
    }

    private function errorResponse(BackupException $exception)
    {
        $error = [
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ];

        if ($exception->details !== []) {
            $error['details'] = $exception->details;
        }

        return response()->json([
            'error' => $error,
        ], $exception->httpStatus);
    }
}
