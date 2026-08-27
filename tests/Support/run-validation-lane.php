<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestingDatabaseLease;
use Tests\Support\TestingDatabaseLeaseException;

require_once __DIR__.'/TestingDatabaseLease.php';

/** @return never */
function validationLaneExit(int $code, string $message): void
{
    fwrite(STDERR, "[validation-lane] {$message}\n");
    exit($code);
}

/** @return array{lane: string, prepare: bool, describe: bool, command: list<string>} */
function parseValidationLaneArguments(array $arguments): array
{
    array_shift($arguments);
    $lane = '';
    $prepare = false;
    $describe = false;
    $command = [];
    $afterSeparator = false;

    foreach ($arguments as $argument) {
        if ($afterSeparator) {
            $command[] = $argument;
            continue;
        }
        if ($argument === '--') {
            $afterSeparator = true;
            continue;
        }
        if (str_starts_with($argument, '--lane=')) {
            $lane = substr($argument, strlen('--lane='));
            continue;
        }
        if ($argument === '--prepare') {
            $prepare = true;
            continue;
        }
        if ($argument === '--describe') {
            $describe = true;
            continue;
        }
        validationLaneExit(TestingDatabaseLease::EXIT_USAGE, 'ARGUMENT_INVALID');
    }

    if (! in_array($lane, ['01', '02', '03', '04'], true)) {
        validationLaneExit(TestingDatabaseLease::EXIT_USAGE, 'LANE_MUST_BE_01_TO_04');
    }
    if (! $prepare && ! $describe && $command === []) {
        validationLaneExit(TestingDatabaseLease::EXIT_USAGE, 'OPERATION_REQUIRED');
    }

    return compact('lane', 'prepare', 'describe', 'command');
}

/** @param list<string> $directories */
function ensureValidationLaneDirectories(array $directories): void
{
    foreach ($directories as $directory) {
        if (is_dir($directory)) {
            continue;
        }
        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            validationLaneExit(TestingDatabaseLease::EXIT_UNAVAILABLE, 'STORAGE_CREATE_FAILED');
        }
    }
}

function setValidationLaneEnvironment(string $name, string $value): void
{
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

/** @param list<string> $command
 *  @return list<string>
 */
function normalizeValidationLaneCommand(array $command): array
{
    if (PHP_OS_FAMILY !== 'Windows' || $command === []) {
        return $command;
    }

    $executable = strtolower(str_replace('\\', '/', $command[0]));
    if (preg_match('~(?:^|/)vendor/bin/phpunit$~', $executable) !== 1) {
        return $command;
    }

    array_unshift($command, PHP_BINARY);

    return $command;
}

$options = parseValidationLaneArguments($argv);
$projectRoot = realpath(dirname(__DIR__, 2));
if ($projectRoot === false) {
    validationLaneExit(TestingDatabaseLease::EXIT_UNAVAILABLE, 'PROJECT_ROOT_INVALID');
}

$lane = $options['lane'];
$storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'linguacafe-validation-lanes'
    .DIRECTORY_SEPARATOR.substr(hash('sha256', strtolower(str_replace('\\', '/', $projectRoot))), 0, 16)
    .DIRECTORY_SEPARATOR.'lane'.$lane.DIRECTORY_SEPARATOR.'storage';
ensureValidationLaneDirectories([
    $storageRoot.DIRECTORY_SEPARATOR.'app',
    $storageRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.'data',
    $storageRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'sessions',
    $storageRoot.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'views',
    $storageRoot.DIRECTORY_SEPARATOR.'logs',
]);

setValidationLaneEnvironment('APP_ENV', 'testing');
setValidationLaneEnvironment('LARAVEL_STORAGE_PATH', $storageRoot);

require $projectRoot.'/vendor/autoload.php';
$app = require $projectRoot.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$connectionName = (string) config('database.default');
$connectionConfig = config("database.connections.{$connectionName}");
if (! is_array($connectionConfig) || ! in_array($connectionConfig['driver'] ?? null, ['mysql', 'mariadb'], true)) {
    validationLaneExit(TestingDatabaseLease::EXIT_UNAVAILABLE, 'MYSQL_TEST_CONNECTION_REQUIRED');
}

$baseDatabase = (string) ($connectionConfig['database'] ?? '');
if ($baseDatabase === ''
    || ! str_contains(strtolower($baseDatabase), 'test')
    || strtolower($baseDatabase) === 'linguacafe_fsrs'
    || preg_match('/^[A-Za-z0-9_]+$/', $baseDatabase) !== 1
) {
    validationLaneExit(TestingDatabaseLease::EXIT_NOT_TESTING, 'UNSAFE_BASE_TEST_DATABASE');
}

$laneDatabase = $baseDatabase.'_lane'.$lane;
if (strlen($laneDatabase) > 64 || preg_match('/^[A-Za-z0-9_]+$/', $laneDatabase) !== 1) {
    validationLaneExit(TestingDatabaseLease::EXIT_UNAVAILABLE, 'LANE_DATABASE_NAME_INVALID');
}

$baseLeaseIdentifier = TestingDatabaseLease::databaseIdentifierFromPhpunitXml($projectRoot.'/phpunit.xml');
$laneLeaseIdentifier = $baseLeaseIdentifier.'-lane'.$lane;
$port = 8870 + (int) $lane;
$browserContext = 'linguacafe-validation-'.$lane;

setValidationLaneEnvironment('DB_DATABASE', $laneDatabase);
setValidationLaneEnvironment('TESTING_DB_LEASE_DATABASE_ID', $laneLeaseIdentifier);
setValidationLaneEnvironment('LINGUACAFE_TEST_DB_LEASE_DATABASE_ID_OVERRIDE', $laneLeaseIdentifier);
setValidationLaneEnvironment('TESTING_DB_LEASE_LABEL', 'validation-lane-'.$lane);

$descriptor = [
    'lane' => $lane,
    'database' => $laneDatabase,
    'lease_id' => $laneLeaseIdentifier,
    'storage_path' => $storageRoot,
    'server_port' => $port,
    'browser_context' => $browserContext,
];

if ($options['describe']) {
    fwrite(STDOUT, json_encode($descriptor, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}

if ($options['prepare']) {
    try {
        $lease = TestingDatabaseLease::acquireForProject(
            $projectRoot,
            label: 'validation-lane-'.$lane.'-prepare',
            databaseIdentifier: $laneLeaseIdentifier,
        );
    } catch (TestingDatabaseLeaseException $error) {
        validationLaneExit(TestingDatabaseLease::exitCodeFor($error), $error->machineCode);
    }

    try {
        $baseConnection = $app['db']->connection($connectionName);
        $pdo = $baseConnection->getPdo();
        $quotedDatabase = '`'.str_replace('`', '``', $laneDatabase).'`';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase}");

        config(["database.connections.{$connectionName}.database" => $laneDatabase]);
        $app['db']->purge($connectionName);
        $exitCode = $kernel->call('migrate', ['--database' => $connectionName]);
        if ($exitCode !== 0) {
            validationLaneExit($exitCode, 'MIGRATION_FAILED');
        }
        fwrite(STDOUT, json_encode($descriptor + ['prepared' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    } finally {
        $lease->release();
    }
}

if ($options['command'] === []) {
    exit(0);
}

$argv = array_merge([
    __DIR__.'/run-with-testing-db-lease.php',
    '--label=validation-lane-'.$lane,
    '--',
], normalizeValidationLaneCommand($options['command']));
require __DIR__.'/run-with-testing-db-lease.php';
