<?php

namespace Tests\Support;

use RuntimeException;
use Throwable;

final class TestingDatabaseLeaseException extends RuntimeException
{
    public function __construct(
        public readonly string $machineCode,
        string $message = 'Testing database lease operation failed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

final class TestingDatabaseLease
{
    public const PROTOCOL_VERSION = 'linguacafe-testing-db-lease-v1';

    public const EXIT_USAGE = 64;

    public const EXIT_BUSY = 73;

    public const EXIT_UNAVAILABLE = 74;

    public const EXIT_SPAWN_FAILED = 75;

    public const EXIT_NOT_TESTING = 78;

    private const DIRECTORY_NAME = 'linguacafe-testing-db-leases';

    private const DATABASE_ID_ENV = 'TESTING_DB_LEASE_DATABASE_ID';

    private const DATABASE_ID_OVERRIDE_ENV = 'LINGUACAFE_TEST_DB_LEASE_DATABASE_ID_OVERRIDE';

    private const TOKEN_ENV = 'LINGUACAFE_TEST_DB_LEASE_TOKEN';

    private const OWNER_PID_ENV = 'LINGUACAFE_TEST_DB_LEASE_OWNER_PID';

    private const IDENTITY_ENV = 'LINGUACAFE_TEST_DB_LEASE_IDENTITY';

    /** @var resource|null */
    private $handle;

    private bool $released = false;

    private bool $inherited = false;

    /** @var list<string> */
    private array $proofFiles = [];

    private function __construct(
        private readonly string $identity,
        private readonly string $directory,
        private readonly string $lockPath,
        private readonly string $metadataPath,
        private readonly string $mode,
        private readonly string $label,
        private readonly string $startedAt,
        $handle,
        bool $inherited = false,
    ) {
        $this->handle = $handle;
        $this->inherited = $inherited;
    }

    public static function acquireForProject(
        string $projectRoot,
        string $mode = 'exclusive',
        string $label = 'phpunit',
        int $waitMs = 0,
        ?string $databaseIdentifier = null,
        ?string $leaseBaseDirectory = null,
    ): self {
        $identity = self::identityForProject($projectRoot, $databaseIdentifier);

        return self::acquireIdentity($identity, $mode, $label, $waitMs, $leaseBaseDirectory);
    }

    public static function acquireOrInheritForProject(
        string $projectRoot,
        string $mode = 'exclusive',
        string $label = 'phpunit',
        int $waitMs = 0,
        ?string $databaseIdentifier = null,
        ?string $leaseBaseDirectory = null,
    ): self {
        $identity = self::identityForProject($projectRoot, $databaseIdentifier);
        $inherited = self::tryInheritedLease($identity, $mode, $label, $leaseBaseDirectory);

        if ($inherited !== null) {
            return $inherited;
        }

        return self::acquireIdentity($identity, $mode, $label, $waitMs, $leaseBaseDirectory);
    }

    public static function acquireIdentity(
        string $identity,
        string $mode = 'exclusive',
        string $label = 'task',
        int $waitMs = 0,
        ?string $leaseBaseDirectory = null,
    ): self {
        self::assertIdentity($identity);
        self::assertMode($mode);

        if ($waitMs < 0 || $waitMs > 3_600_000) {
            throw new TestingDatabaseLeaseException('LEASE_WAIT_INVALID');
        }

        $directory = self::leaseDirectory($leaseBaseDirectory, true);
        [$lockPath, $metadataPath] = self::paths($directory, $identity);
        self::assertSafeRegularFileOrMissing($lockPath);
        self::assertSafeRegularFileOrMissing($metadataPath);

        $handle = @fopen($lockPath, 'c+');
        if (! is_resource($handle)) {
            throw new TestingDatabaseLeaseException('LEASE_LOCK_OPEN_FAILED');
        }
        @chmod($lockPath, 0600);
        self::assertOpenHandleMatchesPath($handle, $lockPath);

        $deadline = hrtime(true) + ($waitMs * 1_000_000);
        do {
            $wouldBlock = 0;
            $locked = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
            if ($locked) {
                break;
            }

            if (! $wouldBlock || $waitMs === 0 || hrtime(true) >= $deadline) {
                fclose($handle);
                throw new TestingDatabaseLeaseException(
                    $wouldBlock ? 'LEASE_BUSY' : 'LEASE_LOCK_FAILED',
                );
            }

            usleep(20_000);
        } while (true);

        self::pruneStaleProofFiles($directory, $identity);
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        $safeLabel = self::sanitizeLabel($label);
        $lease = new self(
            $identity,
            $directory,
            $lockPath,
            $metadataPath,
            $mode,
            $safeLabel,
            $startedAt,
            $handle,
        );

        try {
            $lease->writeOwnerRecord();
        } catch (Throwable $error) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            throw $error;
        }

        register_shutdown_function(static function () use ($lease): void {
            $lease->release();
        });

        return $lease;
    }

    public static function statusForProject(
        string $projectRoot,
        ?string $databaseIdentifier = null,
        ?string $leaseBaseDirectory = null,
    ): array {
        return self::statusIdentity(
            self::identityForProject($projectRoot, $databaseIdentifier),
            $leaseBaseDirectory,
        );
    }

    public static function statusIdentity(
        string $identity,
        ?string $leaseBaseDirectory = null,
    ): array {
        self::assertIdentity($identity);
        $directory = self::leaseDirectory($leaseBaseDirectory, false);
        if (! is_dir($directory)) {
            return self::statusPayload(false, false, null);
        }
        [$lockPath, $metadataPath] = self::paths($directory, $identity);
        self::assertSafeRegularFileOrMissing($lockPath);
        self::assertSafeRegularFileOrMissing($metadataPath);

        $metadataExists = is_file($metadataPath);
        $metadata = self::readJsonFile($metadataPath);
        if (! is_file($lockPath)) {
            return self::statusPayload(false, $metadataExists, $metadata);
        }

        $handle = @fopen($lockPath, 'r+');
        if (! is_resource($handle)) {
            throw new TestingDatabaseLeaseException('LEASE_STATUS_OPEN_FAILED');
        }
        self::assertOpenHandleMatchesPath($handle, $lockPath);

        $wouldBlock = 0;
        $locked = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
        if ($locked) {
            @flock($handle, LOCK_UN);
            fclose($handle);

            return self::statusPayload(false, $metadataExists, $metadata);
        }

        fclose($handle);
        if (! $wouldBlock) {
            throw new TestingDatabaseLeaseException('LEASE_STATUS_CHECK_FAILED');
        }

        return self::statusPayload(true, false, $metadata);
    }

    public static function identityForProject(
        string $projectRoot,
        ?string $databaseIdentifier = null,
    ): string {
        $root = realpath($projectRoot);
        if ($root === false || ! is_dir($root)) {
            throw new TestingDatabaseLeaseException('LEASE_PROJECT_ROOT_INVALID');
        }

        $commonDirOutput = self::runGit($root, ['rev-parse', '--git-common-dir']);
        $commonDir = trim($commonDirOutput);
        if ($commonDir === '') {
            throw new TestingDatabaseLeaseException('LEASE_GIT_COMMON_DIR_MISSING');
        }
        if (! self::isAbsolutePath($commonDir)) {
            $commonDir = $root.DIRECTORY_SEPARATOR.$commonDir;
        }
        $commonDirReal = realpath($commonDir);
        if ($commonDirReal === false || ! is_dir($commonDirReal)) {
            throw new TestingDatabaseLeaseException('LEASE_GIT_COMMON_DIR_INVALID');
        }

        $remote = trim(self::runGit($root, ['config', '--get', 'remote.origin.url'], true));
        if ($remote === '') {
            $remote = 'no-origin-remote';
        }

        if ($databaseIdentifier === null) {
            $runtimeDatabaseIdentifier = trim((string) (getenv(self::DATABASE_ID_OVERRIDE_ENV) ?: ''));
            $databaseIdentifier = $runtimeDatabaseIdentifier !== ''
                ? $runtimeDatabaseIdentifier
                : self::databaseIdentifierFromPhpunitXml($root.'/phpunit.xml');
        }

        return self::identityFromInputs($commonDirReal, $remote, $databaseIdentifier);
    }

    public static function identityFromInputs(
        string $commonDirectory,
        string $remoteIdentity,
        string $databaseIdentifier,
    ): string {
        $normalizedCommonDirectory = self::normalizePathIdentity($commonDirectory);
        $normalizedRemote = self::normalizeRemoteIdentity($remoteIdentity);
        $normalizedDatabase = strtolower(trim($databaseIdentifier));

        if ($normalizedCommonDirectory === '' || $normalizedRemote === '' || $normalizedDatabase === '') {
            throw new TestingDatabaseLeaseException('LEASE_IDENTITY_INPUT_INVALID');
        }
        if (! preg_match('/^[a-z0-9._:-]{3,128}$/', $normalizedDatabase)) {
            throw new TestingDatabaseLeaseException('LEASE_DATABASE_IDENTIFIER_INVALID');
        }

        return hash('sha256', implode("\0", [
            self::PROTOCOL_VERSION,
            $normalizedCommonDirectory,
            $normalizedRemote,
            $normalizedDatabase,
        ]));
    }

    public static function databaseIdentifierFromPhpunitXml(string $phpunitXmlPath): string
    {
        if (! is_file($phpunitXmlPath) || is_link($phpunitXmlPath)) {
            throw new TestingDatabaseLeaseException('LEASE_PHPUNIT_CONFIG_MISSING');
        }

        $xml = @simplexml_load_file($phpunitXmlPath);
        if ($xml === false) {
            throw new TestingDatabaseLeaseException('LEASE_PHPUNIT_CONFIG_INVALID');
        }

        foreach ($xml->php->env ?? [] as $env) {
            if ((string) $env['name'] === self::DATABASE_ID_ENV) {
                $value = trim((string) $env['value']);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        throw new TestingDatabaseLeaseException('LEASE_DATABASE_IDENTIFIER_MISSING');
    }

    public static function lockPathForIdentity(
        string $identity,
        ?string $leaseBaseDirectory = null,
    ): string {
        self::assertIdentity($identity);
        $directory = self::leaseDirectory($leaseBaseDirectory, false);

        return self::paths($directory, $identity)[0];
    }

    public function createInheritanceProof(): array
    {
        if ($this->released) {
            throw new TestingDatabaseLeaseException('LEASE_PROOF_OWNER_REQUIRED');
        }
        if ($this->inherited) {
            $token = getenv(self::TOKEN_ENV);
            $ownerPid = getenv(self::OWNER_PID_ENV);
            $identity = getenv(self::IDENTITY_ENV);
            if (! is_string($token) || $token === ''
                || ! is_string($ownerPid) || ! ctype_digit($ownerPid)
                || ! is_string($identity) || ! hash_equals($this->identity, $identity)
            ) {
                throw new TestingDatabaseLeaseException('LEASE_INHERIT_PROOF_INVALID');
            }

            return [
                self::TOKEN_ENV => $token,
                self::OWNER_PID_ENV => $ownerPid,
                self::IDENTITY_ENV => $identity,
            ];
        }
        if (! is_resource($this->handle)) {
            throw new TestingDatabaseLeaseException('LEASE_PROOF_OWNER_REQUIRED');
        }

        $token = self::base64UrlEncode(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $proofPath = $this->directory.DIRECTORY_SEPARATOR.$this->identity.'.proof-'.$tokenHash.'.json';
        self::assertSafeRegularFileOrMissing($proofPath);
        self::atomicWriteJson($proofPath, [
            'protocol_version' => self::PROTOCOL_VERSION,
            'identity' => $this->identity,
            'owner_pid' => getmypid(),
            'started_at' => $this->startedAt,
            'token_hash' => $tokenHash,
        ]);
        $this->proofFiles[] = $proofPath;

        return [
            self::TOKEN_ENV => $token,
            self::OWNER_PID_ENV => (string) getmypid(),
            self::IDENTITY_ENV => $this->identity,
        ];
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        foreach ($this->proofFiles as $proofFile) {
            self::deleteSafeOwnedFile($proofFile);
        }
        $this->proofFiles = [];

        if ($this->inherited) {
            return;
        }

        if (is_resource($this->handle)) {
            $metadata = self::readJsonFile($this->metadataPath);
            if (is_array($metadata)
                && ($metadata['pid'] ?? null) === getmypid()
                && ($metadata['started_at'] ?? null) === $this->startedAt
            ) {
                self::deleteSafeOwnedFile($this->metadataPath);
            }
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    public function ownsOsLock(): bool
    {
        return ! $this->inherited && ! $this->released && is_resource($this->handle);
    }

    public function isInherited(): bool
    {
        return $this->inherited;
    }

    public function identity(): string
    {
        return $this->identity;
    }

    public function metadata(): array
    {
        return [
            'protocol_version' => self::PROTOCOL_VERSION,
            'pid' => getmypid(),
            'mode' => $this->mode,
            'started_at' => $this->startedAt,
            'label' => $this->label,
        ];
    }

    public static function exitCodeFor(TestingDatabaseLeaseException $error): int
    {
        return match ($error->machineCode) {
            'LEASE_BUSY' => self::EXIT_BUSY,
            'LEASE_ENV_NOT_TESTING' => self::EXIT_NOT_TESTING,
            default => self::EXIT_UNAVAILABLE,
        };
    }

    private static function tryInheritedLease(
        string $identity,
        string $mode,
        string $label,
        ?string $leaseBaseDirectory,
    ): ?self {
        $token = getenv(self::TOKEN_ENV);
        $ownerPid = getenv(self::OWNER_PID_ENV);
        $claimedIdentity = getenv(self::IDENTITY_ENV);
        if (! is_string($token) || $token === ''
            || ! is_string($ownerPid) || ! ctype_digit($ownerPid)
            || ! is_string($claimedIdentity) || $claimedIdentity === ''
        ) {
            return null;
        }
        if (! hash_equals($identity, $claimedIdentity)) {
            throw new TestingDatabaseLeaseException('LEASE_INHERIT_IDENTITY_MISMATCH');
        }

        $directory = self::leaseDirectory($leaseBaseDirectory, false);
        if (! is_dir($directory)) {
            return null;
        }
        [$lockPath, $metadataPath] = self::paths($directory, $identity);
        if (! is_file($lockPath)) {
            return null;
        }

        self::assertSafeRegularFileOrMissing($lockPath);
        self::assertSafeRegularFileOrMissing($metadataPath);
        $handle = @fopen($lockPath, 'r+');
        if (! is_resource($handle)) {
            throw new TestingDatabaseLeaseException('LEASE_INHERIT_LOCK_OPEN_FAILED');
        }
        self::assertOpenHandleMatchesPath($handle, $lockPath);

        $wouldBlock = 0;
        $locked = @flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
        if ($locked) {
            @flock($handle, LOCK_UN);
            fclose($handle);

            return null;
        }
        fclose($handle);
        if (! $wouldBlock) {
            throw new TestingDatabaseLeaseException('LEASE_INHERIT_LOCK_CHECK_FAILED');
        }

        $metadata = self::readJsonFile($metadataPath);
        $tokenHash = hash('sha256', $token);
        $proofPath = $directory.DIRECTORY_SEPARATOR.$identity.'.proof-'.$tokenHash.'.json';
        self::assertSafeRegularFileOrMissing($proofPath);
        $proof = self::readJsonFile($proofPath);
        $pid = (int) $ownerPid;

        $valid = is_array($metadata)
            && is_array($proof)
            && ($metadata['protocol_version'] ?? null) === self::PROTOCOL_VERSION
            && ($proof['protocol_version'] ?? null) === self::PROTOCOL_VERSION
            && ($metadata['pid'] ?? null) === $pid
            && ($proof['owner_pid'] ?? null) === $pid
            && ($proof['identity'] ?? null) === $identity
            && ($proof['started_at'] ?? null) === ($metadata['started_at'] ?? null)
            && ($proof['token_hash'] ?? null) === $tokenHash;

        if (! $valid) {
            throw new TestingDatabaseLeaseException('LEASE_INHERIT_PROOF_INVALID');
        }

        return new self(
            $identity,
            $directory,
            $lockPath,
            $metadataPath,
            $mode,
            self::sanitizeLabel($label),
            (string) $metadata['started_at'],
            null,
            true,
        );
    }

    private function writeOwnerRecord(): void
    {
        $record = $this->metadata();
        if (! @ftruncate($this->handle, 0)) {
            throw new TestingDatabaseLeaseException('LEASE_LOCK_RECORD_TRUNCATE_FAILED');
        }
        rewind($this->handle);
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (@fwrite($this->handle, $encoded) !== strlen($encoded)) {
            throw new TestingDatabaseLeaseException('LEASE_LOCK_RECORD_WRITE_FAILED');
        }
        @fflush($this->handle);
        self::atomicWriteJson($this->metadataPath, $record);
    }

    private static function statusPayload(bool $active, bool $staleMetadata, ?array $metadata): array
    {
        $validMetadata = is_array($metadata)
            && ($metadata['protocol_version'] ?? null) === self::PROTOCOL_VERSION
            && is_int($metadata['pid'] ?? null)
            && in_array($metadata['mode'] ?? null, ['exclusive'], true)
            && is_string($metadata['started_at'] ?? null)
            && is_string($metadata['label'] ?? null);

        return [
            'active' => $active,
            'stale_metadata' => $staleMetadata,
            'metadata_valid' => $validMetadata,
            'protocol_version' => $validMetadata ? $metadata['protocol_version'] : null,
            'pid' => $validMetadata ? $metadata['pid'] : null,
            'mode' => $validMetadata ? $metadata['mode'] : null,
            'started_at' => $validMetadata ? $metadata['started_at'] : null,
            'label' => $validMetadata ? $metadata['label'] : null,
        ];
    }

    private static function paths(string $directory, string $identity): array
    {
        return [
            $directory.DIRECTORY_SEPARATOR.$identity.'.lock',
            $directory.DIRECTORY_SEPARATOR.$identity.'.json',
        ];
    }

    private static function pruneStaleProofFiles(string $directory, string $identity): void
    {
        $entries = @scandir($directory);
        if (! is_array($entries)) {
            throw new TestingDatabaseLeaseException('LEASE_DIRECTORY_READ_FAILED');
        }
        $pattern = '/^'.preg_quote($identity, '/').'\.proof-[a-f0-9]{64}\.json$/';
        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry) !== 1) {
                continue;
            }
            self::deleteSafeOwnedFile($directory.DIRECTORY_SEPARATOR.$entry);
        }
    }

    private static function leaseDirectory(?string $leaseBaseDirectory, bool $create): string
    {
        $base = $leaseBaseDirectory ?? sys_get_temp_dir();
        if (! self::isAbsolutePath($base)) {
            throw new TestingDatabaseLeaseException('LEASE_BASE_DIRECTORY_UNSAFE');
        }
        $baseReal = realpath($base);
        if ($baseReal === false || ! is_dir($baseReal) || is_link($base)) {
            throw new TestingDatabaseLeaseException('LEASE_BASE_DIRECTORY_UNSAFE');
        }
        // Windows may expose the same directory through a short (8.3) path and
        // a long realpath. Reject actual links above, but do not confuse an
        // equivalent filesystem alias with a reparse point.
        $directory = $baseReal.DIRECTORY_SEPARATOR.self::DIRECTORY_NAME.DIRECTORY_SEPARATOR.'v1';
        $current = $baseReal;
        foreach ([self::DIRECTORY_NAME, 'v1'] as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (file_exists($current)) {
                if (! is_dir($current) || is_link($current)) {
                    throw new TestingDatabaseLeaseException('LEASE_DIRECTORY_UNSAFE');
                }
            } elseif ($create) {
                if (! @mkdir($current, 0700)
                    && (! is_dir($current) || is_link($current))
                ) {
                    throw new TestingDatabaseLeaseException('LEASE_DIRECTORY_CREATE_FAILED');
                }
            } else {
                return $directory;
            }

            $currentReal = realpath($current);
            if ($currentReal === false
                || self::normalizePathIdentity($currentReal) !== self::normalizePathIdentity($current)
            ) {
                throw new TestingDatabaseLeaseException('LEASE_DIRECTORY_REPARSE_REJECTED');
            }
            if (PHP_OS_FAMILY !== 'Windows') {
                $directoryStat = @lstat($current);
                if (! is_array($directoryStat)
                    || (int) ($directoryStat['uid'] ?? -1) !== (int) getmyuid()
                    || (($directoryStat['mode'] ?? 0) & 0077) !== 0
                ) {
                    throw new TestingDatabaseLeaseException('LEASE_DIRECTORY_PERMISSIONS_UNSAFE');
                }
            }
        }

        return $directory;
    }

    private static function atomicWriteJson(string $path, array $payload): void
    {
        self::assertSafeRegularFileOrMissing($path);
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'x');
        if (! is_resource($handle)) {
            throw new TestingDatabaseLeaseException('LEASE_METADATA_TEMP_OPEN_FAILED');
        }
        @chmod($temporary, 0600);
        self::assertOpenHandleMatchesPath($handle, $temporary);

        try {
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (@fwrite($handle, $encoded) !== strlen($encoded)) {
                throw new TestingDatabaseLeaseException('LEASE_METADATA_WRITE_FAILED');
            }
            @fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            @fclose($handle);
        }

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new TestingDatabaseLeaseException('LEASE_METADATA_REPLACE_FAILED');
        }
    }

    private static function readJsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        self::assertSafeRegularFileOrMissing($path);
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            return null;
        }
        try {
            self::assertOpenHandleMatchesPath($handle, $path);
            $contents = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if (! is_string($contents) || $contents === '') {
            return null;
        }
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function deleteSafeOwnedFile(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        self::assertSafeRegularFileOrMissing($path);
        @unlink($path);
    }

    private static function assertSafeRegularFileOrMissing(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        $stat = @lstat($path);
        if (! is_array($stat)) {
            clearstatcache(true, $path);
            if (! file_exists($path) && ! is_link($path)) {
                return;
            }
            throw new TestingDatabaseLeaseException('LEASE_FILE_UNSAFE');
        }
        if (is_link($path)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 1) !== 1
        ) {
            throw new TestingDatabaseLeaseException('LEASE_FILE_UNSAFE');
        }
        if (PHP_OS_FAMILY !== 'Windows'
            && ((int) ($stat['uid'] ?? -1) !== (int) getmyuid()
                || (($stat['mode'] ?? 0) & 0077) !== 0)
        ) {
            throw new TestingDatabaseLeaseException('LEASE_FILE_PERMISSIONS_UNSAFE');
        }
    }

    /** @param resource $handle */
    private static function assertOpenHandleMatchesPath($handle, string $path): void
    {
        $handleStat = @fstat($handle);
        $pathStat = @lstat($path);
        if (! is_array($handleStat) || ! is_array($pathStat)
            || (($handleStat['mode'] ?? 0) & 0170000) !== 0100000
            || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($handleStat['nlink'] ?? 1) !== 1
            || (int) ($pathStat['nlink'] ?? 1) !== 1
        ) {
            throw new TestingDatabaseLeaseException('LEASE_FILE_HANDLE_UNSAFE');
        }
        foreach (['dev', 'ino'] as $field) {
            if (isset($handleStat[$field], $pathStat[$field])
                && (int) $handleStat[$field] !== (int) $pathStat[$field]
            ) {
                throw new TestingDatabaseLeaseException('LEASE_FILE_HANDLE_MISMATCH');
            }
        }
        if (PHP_OS_FAMILY !== 'Windows'
            && ((int) ($handleStat['uid'] ?? -1) !== (int) getmyuid()
                || (($handleStat['mode'] ?? 0) & 0077) !== 0)
        ) {
            throw new TestingDatabaseLeaseException('LEASE_FILE_PERMISSIONS_UNSAFE');
        }
    }

    private static function assertIdentity(string $identity): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $identity)) {
            throw new TestingDatabaseLeaseException('LEASE_IDENTITY_INVALID');
        }
    }

    private static function assertMode(string $mode): void
    {
        if ($mode !== 'exclusive') {
            throw new TestingDatabaseLeaseException('LEASE_MODE_UNSUPPORTED');
        }
    }

    private static function sanitizeLabel(string $label): string
    {
        $normalized = strtolower(trim($label));
        $containsSensitiveShape = preg_match('/[\\\\\/:=@]/', $normalized) === 1
            || preg_match('/(?:password|secret|token|credential|key)/i', $normalized) === 1;

        if ($containsSensitiveShape) {
            return 'task-'.substr(hash('sha256', $normalized), 0, 16);
        }

        $normalized = preg_replace('/[^a-z0-9._-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-._');

        return substr($normalized !== '' ? $normalized : 'task', 0, 48);
    }

    private static function normalizePathIdentity(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = rtrim($path, '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private static function normalizeRemoteIdentity(string $remote): string
    {
        $remote = trim($remote);
        $remote = preg_replace('#^[a-z][a-z0-9+.-]*://[^/@]+@#i', 'https://', $remote) ?? $remote;
        $remote = preg_replace('#\.git$#i', '', $remote) ?? $remote;
        $remote = rtrim(str_replace('\\', '/', $remote), '/');

        if (preg_match('#^([a-z][a-z0-9+.-]*://)([^/]+)(/.*)?$#i', $remote, $matches)) {
            $remote = strtolower($matches[1].$matches[2]).($matches[3] ?? '');
        } elseif (preg_match('/^([^@]+@)?([^:]+):(.*)$/', $remote, $matches)) {
            $remote = strtolower($matches[2]).'/'.$matches[3];
        }

        return $remote;
    }

    private static function runGit(string $root, array $arguments, bool $allowFailure = false): string
    {
        $command = array_merge(['git', '-C', $root], $arguments);
        $descriptors = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new TestingDatabaseLeaseException('LEASE_GIT_START_FAILED');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = hrtime(true) + 5_000_000_000;
        $lastStatus = null;
        $forced = false;

        while (true) {
            $read = [];
            if (! feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (! feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read !== []) {
                $write = [];
                $except = [];
                @stream_select($read, $write, $except, 0, 100_000);
                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($stream === $pipes[1]) {
                        $stdout .= is_string($chunk) ? $chunk : '';
                    } else {
                        $stderr .= is_string($chunk) ? $chunk : '';
                    }
                }
            }

            $lastStatus = @proc_get_status($process);
            if (! is_array($lastStatus) || ! ($lastStatus['running'] ?? false)) {
                break;
            }

            if (hrtime(true) >= $deadline) {
                @proc_terminate($process, $forced ? 9 : 15);
                if ($forced) {
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    @proc_close($process);
                    throw new TestingDatabaseLeaseException('LEASE_GIT_TIMEOUT');
                }
                $forced = true;
                $deadline = hrtime(true) + 1_000_000_000;
            }
        }

        $remainingStdout = stream_get_contents($pipes[1]);
        $remainingStderr = stream_get_contents($pipes[2]);
        $stdout .= is_string($remainingStdout) ? $remainingStdout : '';
        $stderr .= is_string($remainingStderr) ? $remainingStderr : '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        $exitCode = $closeCode;
        if ($exitCode === -1 && is_array($lastStatus) && is_int($lastStatus['exitcode'] ?? null)) {
            $exitCode = $lastStatus['exitcode'];
        }

        if ($exitCode !== 0 && ! $allowFailure) {
            throw new TestingDatabaseLeaseException('LEASE_GIT_COMMAND_FAILED');
        }

        return $exitCode === 0 ? $stdout : '';
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
