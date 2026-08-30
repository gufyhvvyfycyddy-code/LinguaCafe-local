<?php

declare(strict_types=1);

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'];
array_shift($arguments);

$timeoutSeconds = null;
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
    if (str_starts_with($argument, '--seconds=')) {
        $value = substr($argument, strlen('--seconds='));
        if ($value === '' || !ctype_digit($value) || (int) $value < 1 || (int) $value > 3600) {
            fwrite(STDERR, "COMMAND_TIMEOUT_INVALID\n");
            exit(64);
        }
        $timeoutSeconds = (int) $value;
        continue;
    }

    fwrite(STDERR, "COMMAND_TIMEOUT_ARGUMENT_INVALID\n");
    exit(64);
}

if ($timeoutSeconds === null || $command === []) {
    fwrite(STDERR, "COMMAND_TIMEOUT_USAGE\n");
    exit(64);
}

$process = new Process($command, dirname(__DIR__, 2));
$process->setTimeout($timeoutSeconds);

try {
    $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });
} catch (ProcessTimedOutException) {
    fwrite(STDERR, "COMMAND_TIMEOUT_EXCEEDED seconds={$timeoutSeconds}\n");
    exit(124);
}

exit($process->getExitCode() ?? 1);
