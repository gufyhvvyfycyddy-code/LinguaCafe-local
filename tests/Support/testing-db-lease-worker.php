<?php

use Tests\Support\TestingDatabaseLease;
use Tests\Support\TestingDatabaseLeaseException;

require_once __DIR__.'/TestingDatabaseLease.php';

function parseBoundedProbeHoldMs(string $value): int
{
    if ($value === ''
        || ! ctype_digit($value)
        || strlen($value) > 6
        || (int) $value < 100
        || (int) $value > 60_000
    ) {
        fwrite(STDERR, "LEASE_WORKER_HOLD_MS_INVALID\n");
        exit(TestingDatabaseLease::EXIT_USAGE);
    }

    return (int) $value;
}

$operation = $argv[1] ?? '';
$identity = $argv[2] ?? '';
$baseDirectory = $argv[3] ?? '';
$baseDirectory = $baseDirectory !== '' ? $baseDirectory : null;
$label = $argv[4] ?? 'worker';

try {
    if ($operation === 'project-probe-hold') {
        $projectRoot = $argv[2] ?? '';
        $databaseIdentifier = $argv[3] ?? '';
        $leaseBaseDirectory = ($argv[4] ?? '') !== '' ? $argv[4] : null;
        $projectLabel = $argv[5] ?? 'project-probe';
        $maxHoldMs = parseBoundedProbeHoldMs($argv[6] ?? '');
        $lease = TestingDatabaseLease::acquireOrInheritForProject(
            $projectRoot,
            label: $projectLabel,
            databaseIdentifier: $databaseIdentifier,
            leaseBaseDirectory: $leaseBaseDirectory,
        );
        fwrite(
            STDOUT,
            'READY '.(string) getmypid().' '.($lease->isInherited() ? 'INHERITED' : 'OWNED')."\n",
        );
        fflush(STDOUT);
        $deadline = hrtime(true) + ($maxHoldMs * 1_000_000);
        while (hrtime(true) < $deadline) {
            usleep(50_000);
        }
        $lease->release();
        fwrite(STDOUT, "EXPIRED\n");
        exit(0);
    }

    if ($operation === 'project-hold' || $operation === 'project-try') {
        $projectRoot = $argv[2] ?? '';
        $databaseIdentifier = $argv[3] ?? '';
        $projectLabel = $argv[4] ?? 'project-worker';
        $lease = TestingDatabaseLease::acquireForProject(
            $projectRoot,
            label: $projectLabel,
            databaseIdentifier: $databaseIdentifier,
        );
        if ($operation === 'project-try') {
            fwrite(STDOUT, "ACQUIRED\n");
            $lease->release();
            exit(0);
        }
        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);
        $command = fgets(STDIN);
        if (trim((string) $command) !== 'RELEASE') {
            exit(76);
        }
        $lease->release();
        fwrite(STDOUT, "RELEASED\n");
        exit(0);
    }

    if ($operation === 'status') {
        fwrite(STDOUT, json_encode(
            TestingDatabaseLease::statusIdentity($identity, $baseDirectory),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");
        exit(0);
    }

    if ($operation === 'try') {
        $lease = TestingDatabaseLease::acquireIdentity(
            $identity,
            label: $label,
            leaseBaseDirectory: $baseDirectory,
        );
        fwrite(STDOUT, "ACQUIRED\n");
        $lease->release();
        exit(0);
    }

    if ($operation === 'hold') {
        $lease = TestingDatabaseLease::acquireIdentity(
            $identity,
            label: $label,
            leaseBaseDirectory: $baseDirectory,
        );
        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);
        $command = fgets(STDIN);
        if (trim((string) $command) !== 'RELEASE') {
            exit(76);
        }
        $lease->release();
        fwrite(STDOUT, "RELEASED\n");
        exit(0);
    }

    if ($operation === 'compete' || $operation === 'wait-acquire') {
        fwrite(STDOUT, "WAITING\n");
        fflush(STDOUT);
        $command = fgets(STDIN);
        if (trim((string) $command) !== 'GO') {
            exit(76);
        }
        $waitMs = $operation === 'wait-acquire' ? (int) ($argv[5] ?? 0) : 0;
        try {
            if ($operation === 'wait-acquire') {
                try {
                    $lease = TestingDatabaseLease::acquireIdentity(
                        $identity,
                        label: $label,
                        leaseBaseDirectory: $baseDirectory,
                    );
                } catch (TestingDatabaseLeaseException $initialError) {
                    if ($initialError->machineCode !== 'LEASE_BUSY') {
                        throw $initialError;
                    }
                    fwrite(STDOUT, "BLOCKED\n");
                    fflush(STDOUT);
                    $lease = TestingDatabaseLease::acquireIdentity(
                        $identity,
                        label: $label,
                        waitMs: $waitMs,
                        leaseBaseDirectory: $baseDirectory,
                    );
                }
            } else {
                $lease = TestingDatabaseLease::acquireIdentity(
                    $identity,
                    label: $label,
                    leaseBaseDirectory: $baseDirectory,
                );
            }
        } catch (TestingDatabaseLeaseException $error) {
            if ($error->machineCode === 'LEASE_BUSY') {
                fwrite(STDOUT, "BUSY\n");
                exit(TestingDatabaseLease::EXIT_BUSY);
            }
            throw $error;
        }
        fwrite(STDOUT, "ACQUIRED\n");
        fflush(STDOUT);
        $command = fgets(STDIN);
        if (trim((string) $command) !== 'RELEASE') {
            exit(76);
        }
        $lease->release();
        fwrite(STDOUT, "RELEASED\n");
        exit(0);
    }

    if ($operation === 'inherit') {
        $lease = TestingDatabaseLease::acquireOrInheritForProject(
            $argv[2] ?? '',
            label: $label,
            leaseBaseDirectory: $baseDirectory,
        );
        fwrite(STDOUT, $lease->isInherited() ? "INHERITED\n" : "OWNED\n");
        $lease->release();
        exit(0);
    }

    fwrite(STDERR, "LEASE_WORKER_USAGE\n");
    exit(64);
} catch (TestingDatabaseLeaseException $error) {
    fwrite(STDERR, $error->machineCode."\n");
    exit(TestingDatabaseLease::exitCodeFor($error));
} catch (Throwable) {
    fwrite(STDERR, "LEASE_WORKER_FAILED\n");
    exit(74);
}
