<?php

use App\Services\UserService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (strtolower((string) (getenv('APP_ENV') ?: '')) !== 'testing') {
    fwrite(STDERR, "IOS_READER_PAB_ENV_NOT_TESTING\n");
    exit(78);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$database = strtolower((string) DB::connection()->getDatabaseName());
$sentinel = (string) (getenv('LINGUACAFE_TEST_SENTINEL') ?: '');
$sentinelReady = str_contains($database, 'test')
    && str_starts_with($sentinel, '__testing_acceptance_sentinel_')
    && DB::table('migrations')->where('migration', $sentinel)->exists();
if (!$sentinelReady) {
    fwrite(STDERR, "IOS_READER_PAB_SENTINEL_NOT_READY\n");
    exit(78);
}

$email = trim((string) (getenv('LC_TEST_EMAIL') ?: ''));
$password = (string) (getenv('LC_TEST_PASSWORD') ?: '');
$marker = trim((string) (getenv('LC_READER_MARKER') ?: 'h10_ios_reader'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "IOS_READER_PAB_IDENTITY_INVALID\n");
    exit(64);
}

$projectRoot = dirname(__DIR__, 2);
chdir($projectRoot);
$focusedTest = escapeshellarg(PHP_BINARY)
    .' '.escapeshellarg($projectRoot.'/vendor/bin/phpunit')
    .' '.escapeshellarg($projectRoot.'/tests/Feature/H10IosReaderBindingTest.php');
passthru($focusedTest, $focusedTestExit);
if ($focusedTestExit !== 0) {
    fwrite(STDERR, "IOS_READER_PAB_FOCUSED_TEST_FAILED\n");
    exit($focusedTestExit);
}

app(UserService::class)->createUser('iOS Reader CI', $email, $password, true, true);
$exitCode = Artisan::call('smoke:mobile-reader-data', [
    '--email' => $email,
    '--marker' => $marker,
    '--json' => true,
]);
if ($exitCode !== 0) {
    fwrite(STDERR, "IOS_READER_PAB_FIXTURE_FAILED\n");
    exit($exitCode);
}

fwrite(STDOUT, "IOS_READER_PAB_FIXTURE_READY\n");
if (!function_exists('pcntl_exec')) {
    fwrite(STDERR, "IOS_READER_PAB_PCNTL_REQUIRED\n");
    exit(78);
}

pcntl_exec(PHP_BINARY, [
    'artisan',
    'serve',
    '--host=127.0.0.1',
    '--port=8878',
    '--no-reload',
]);

fwrite(STDERR, "IOS_READER_PAB_SERVER_EXEC_FAILED\n");
exit(70);
