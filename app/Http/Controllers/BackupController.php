<?php

namespace App\Http\Controllers;

use App\Exceptions\BackupException;
use App\Services\BackupService;
use Illuminate\Http\Request;

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
}
