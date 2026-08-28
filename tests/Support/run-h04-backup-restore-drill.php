<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

final class H04BackupRestoreDrillFailure extends RuntimeException
{
    public function __construct(public readonly string $machineCode, ?Throwable $previous = null)
    {
        parent::__construct($machineCode, 0, $previous);
    }
}

function h04DrillProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @param list<string> $command */
function h04DrillRun(array $command, float $timeoutSeconds = 120): string
{
    $process = new Process($command, h04DrillProjectRoot());
    $process->setTimeout($timeoutSeconds);
    $process->run();

    if (! $process->isSuccessful()) {
        $tail = trim($process->getErrorOutput() !== ''
            ? $process->getErrorOutput()
            : $process->getOutput());
        if ($tail !== '') {
            fwrite(STDERR, '[h04-drill] command_tail: '.substr($tail, -4000)."\n");
        }
        throw new H04BackupRestoreDrillFailure('H04_DRILL_COMMAND_FAILED');
    }

    return trim($process->getOutput());
}

/** @param list<string> $command
 *  @return array<string,mixed>
 */
function h04DrillRunJson(array $command, float $timeoutSeconds = 120): array
{
    try {
        $decoded = json_decode(
            h04DrillRun($command, $timeoutSeconds),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    } catch (Throwable $error) {
        throw new H04BackupRestoreDrillFailure('H04_DRILL_JSON_INVALID', $error);
    }

    if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1) {
        throw new H04BackupRestoreDrillFailure('H04_DRILL_JSON_INVALID');
    }

    return $decoded;
}

function h04DrillWaitForLogin(string $url): void
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 2.0,
            'ignore_errors' => true,
        ],
    ]);
    $deadline = microtime(true) + 20.0;

    do {
        $body = @file_get_contents($url, false, $context);
        $status = $http_response_header[0] ?? '';
        if (is_string($body) && preg_match('/\s200\s/', $status) === 1) {
            return;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new H04BackupRestoreDrillFailure('H04_DRILL_WEB_NOT_READY');
}

function h04DrillLockPath(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'linguacafe-h04-backup-restore-drill.lock';
}

function h04DrillAcquireLock()
{
    $path = h04DrillLockPath();
    $handle = @fopen($path, 'c+');
    if (! is_resource($handle)) {
        throw new H04BackupRestoreDrillFailure('H04_DRILL_LOCK_UNAVAILABLE');
    }
    if (! @flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new H04BackupRestoreDrillFailure('H04_DRILL_ALREADY_RUNNING');
    }

    return $handle;
}

/** @return array<string,mixed> */
function h04DrillFenceProbe(string $compose, int $adminId): array
{
    $helper = 'tests/Support/run-h04-container-runtime.php';
    $deadline = microtime(true) + 15.0;

    do {
        $status = h04DrillRunJson([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
            'php', $helper, '--fence-status',
        ], 30);
        if (($status['fence']['active'] ?? null) === true) {
            $write = h04DrillRunJson([
                'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
                'php', $helper, '--attempt-write', '--admin-id='.$adminId,
            ], 30);
            if (($write['write_probe']['blocked'] ?? null) !== true
                || ($write['write_probe']['error_code'] ?? null) !== 'BACKUP_RESTORE_WRITE_FENCE_ACTIVE') {
                throw new H04BackupRestoreDrillFailure('H04_DRILL_WRITE_FENCE_UNPROVEN');
            }

            return $write['write_probe'];
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new H04BackupRestoreDrillFailure('H04_DRILL_WRITE_FENCE_NOT_OBSERVED');
}

function runH04BackupRestoreDrillCli(array $arguments): int
{
    $rollbackDrill = in_array('--rollback', $arguments, true);
    $root = h04DrillProjectRoot();
    $compose = $root.'/docker-compose.h04-testing.yml';
    $helper = 'tests/Support/run-h04-container-runtime.php';
    $lock = null;
    $worker = null;

    try {
        $lock = h04DrillAcquireLock();
        h04DrillRun(['docker.exe', 'compose', '-f', $compose, 'down'], 60);
        h04DrillRun(['docker.exe', 'compose', '-f', $compose, 'config', '--quiet'], 30);
        h04DrillRun(['docker.exe', 'compose', '-f', $compose, 'build', '--quiet', 'web'], 600);
        h04DrillRun(['docker.exe', 'compose', '-f', $compose, 'up', '-d'], 180);
        $mysqlClientVersion = h04DrillRun([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web', 'mysql', '--version',
        ], 30);
        $mysqldumpClientVersion = h04DrillRun([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web', 'mysqldump', '--version',
        ], 30);
        foreach ([$mysqlClientVersion, $mysqldumpClientVersion] as $version) {
            if (! str_contains($version, 'MySQL Community Server')
                || preg_match('/\bVer\s+8\./', $version) !== 1) {
                throw new H04BackupRestoreDrillFailure('H04_DRILL_MYSQL_CLIENT_INCOMPATIBLE');
            }
        }

        h04DrillRun([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
            'php', 'artisan', 'migrate', '--no-interaction',
        ], 180);
        h04DrillWaitForLogin('http://127.0.0.1:8894/login');

        $preparedEnvelope = h04DrillRunJson([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
            'php', $helper, '--prepare',
        ], 180);
        $prepared = $preparedEnvelope['prepared'] ?? null;
        if (! is_array($prepared)) {
            throw new H04BackupRestoreDrillFailure('H04_DRILL_PREPARE_INVALID');
        }

        $backupId = (string) ($prepared['backup']['backup_id'] ?? '');
        $adminId = (int) ($prepared['baseline']['admin_id'] ?? 0);
        $studyUserId = (int) ($prepared['baseline']['study_user_id'] ?? 0);
        $reviewCardId = (int) ($prepared['baseline']['review_card_id'] ?? 0);
        if ($backupId === '' || $adminId < 1 || $studyUserId < 1 || $reviewCardId < 1) {
            throw new H04BackupRestoreDrillFailure('H04_DRILL_PREPARE_INVALID');
        }

        $confirmEnvelope = h04DrillRunJson([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
            'php', $helper, '--confirm', '--backup-id='.$backupId, '--user-id='.$adminId,
        ], 180);
        $operation = $confirmEnvelope['operation'] ?? null;
        $operationId = is_array($operation) ? (string) ($operation['operation_id'] ?? '') : '';
        if ($operationId === '' || ($operation['status'] ?? null) !== 'queued') {
            throw new H04BackupRestoreDrillFailure('H04_DRILL_CONFIRM_INVALID');
        }

        $worker = new Process([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
            'php', 'artisan', 'queue:work', 'redis-restore',
            '--queue=maintenance', '--once', '--timeout=21600', '--sleep=1', '--no-interaction',
        ], $root);
        $worker->setTimeout(180);
        $worker->start();

        $writeFence = h04DrillFenceProbe($compose, $adminId);
        if ($rollbackDrill) {
            $tamper = h04DrillRunJson([
                'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
                'php', $helper, '--tamper-pinned-target', '--operation-id='.$operationId,
            ], 30);
            if (($tamper['tamper']['tampered'] ?? null) !== true) {
                throw new H04BackupRestoreDrillFailure('H04_DRILL_TAMPER_FAILED');
            }
        }
        $worker->wait();
        if (! $worker->isSuccessful()) {
            $tail = trim($worker->getErrorOutput() !== ''
                ? $worker->getErrorOutput()
                : $worker->getOutput());
            if ($tail !== '') {
                fwrite(STDERR, '[h04-drill] worker_tail: '.substr($tail, -4000)."\n");
            }
            throw new H04BackupRestoreDrillFailure('H04_DRILL_WORKER_FAILED');
        }
        $worker = null;

        $verifyEnvelope = h04DrillRunJson([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T', 'web',
            'php', $helper, $rollbackDrill ? '--verify-rollback' : '--verify',
            '--operation-id='.$operationId,
            '--admin-id='.$adminId,
            '--study-user-id='.$studyUserId,
            '--review-card-id='.$reviewCardId,
        ], 60);
        $verification = $verifyEnvelope['verification'] ?? null;
        if (! is_array($verification) || ($verification['ok'] ?? null) !== true) {
            throw new H04BackupRestoreDrillFailure('H04_DRILL_VERIFICATION_FAILED');
        }

        $temporaryDatabaseCount = (int) trim(h04DrillRun([
            'docker.exe', 'compose', '-f', $compose, 'exec', '-T',
            '-e', 'MYSQL_PWD=h04-root-testing-only', 'mysql',
            'mysql', '-uroot', '--batch', '--skip-column-names',
            '--execute=SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE \'linguacafe\\_restore\\_test\\_%\'',
        ], 30));
        if ($temporaryDatabaseCount !== 0) {
            throw new H04BackupRestoreDrillFailure('H04_DRILL_VALIDATION_DATABASE_RESIDUE');
        }

        $gitHead = h04DrillRun(['git', 'rev-parse', 'HEAD'], 30);
        if (preg_match('/\A[0-9a-f]{40}\z/i', $gitHead) !== 1) {
            throw new H04BackupRestoreDrillFailure('H04_DRILL_GIT_HEAD_INVALID');
        }

        fwrite(STDOUT, json_encode([
            'schema_version' => 1,
            'tool' => 'linguacafe-h04-backup-restore-drill',
            'scenario' => $rollbackDrill ? 'automatic-safety-rollback' : 'successful-restore',
            'git_head' => $gitHead,
            'runtime' => [
                'database' => 'linguacafe_h04_testing',
                'database_disposable' => true,
                'redis_coordination' => true,
                'redis_restore_queue' => true,
                'validation_identity' => 'root (disposable H-04 MySQL only)',
                'validation_database_residue' => $temporaryDatabaseCount,
                'mysql_client' => $mysqlClientVersion,
                'mysqldump_client' => $mysqldumpClientVersion,
            ],
            'prepared' => $prepared,
            'operation_id' => $operationId,
            'write_fence_probe' => $writeFence,
            'verification' => $verification,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return 0;
    } catch (H04BackupRestoreDrillFailure $error) {
        fwrite(STDERR, '[h04-drill] '.$error->machineCode."\n");

        return 78;
    } catch (Throwable $error) {
        fwrite(STDERR, '[h04-drill] H04_DRILL_UNEXPECTED_FAILURE: '.$error->getMessage()."\n");

        return 78;
    } finally {
        if ($worker instanceof Process && $worker->isRunning()) {
            $worker->stop(2);
        }
        if (is_resource($lock)) {
            try {
                h04DrillRun(['docker.exe', 'compose', '-f', $compose, 'down'], 60);
            } catch (Throwable) {
                fwrite(STDERR, "[h04-drill] H04_DRILL_CLEANUP_FAILED\n");
            }
            @flock($lock, LOCK_UN);
            fclose($lock);
            @unlink(h04DrillLockPath());
        }
    }
}

$scriptPath = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptPath)
    && realpath($scriptPath) !== false
    && realpath($scriptPath) === realpath(__FILE__)
) {
    exit(runH04BackupRestoreDrillCli($argv));
}
