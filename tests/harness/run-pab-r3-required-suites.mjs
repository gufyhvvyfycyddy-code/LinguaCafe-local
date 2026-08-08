#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const integration = process.argv.includes('--integration');

const phpPure = [
    ['AiReadingAssistV1CompatParserTest', 'tests/Unit/AiReadingAssistV1CompatParserTest.php'],
    ['AiReadingAssistV2StrictParserTest', 'tests/Unit/AiReadingAssistV2StrictParserTest.php'],
    ['AiReadingAssistV2BatchingTest', 'tests/Unit/AiReadingAssistV2BatchingTest.php'],
];
const phpDb = [
    'AiReadingAssistV2CandidateOwnershipTest',
    'AiReadingAssistV2WriteBoundaryTest',
    'ReadingReviewSettlementContractTest',
    'ReadingReviewConcurrencyContractTest',
    'ReadingUnfamiliarTargetSnapshotConflictTest',
    'ReadingReviewSourceUndoAnalyticsTest',
];
const nodeSuites = [
    ['tests/js/AiReadingAssistV1CompatibilityGuard.test.mjs', 'passed'],
    ['tests/js/PhaseAReviewWriteSurfaceGuard.test.mjs', 'passed'],
    ['tests/js/PhaseBFormalRatingWriteSurfaceGuard.test.mjs', 'passed'],
    ['tests/js/ReadingRatingSourceContractGuard.test.mjs', 'passed'],
];

const forbiddenOutcome = /\b(?:incomplete|skipped|pending)\b|No tests found|No tests executed|No tests!/i;

function run(command, args, label, requirePhpSummary = false, expectedMarker = null) {
    const result = spawnSync(command, args, {
        cwd: root,
        encoding: 'utf8',
        env: process.env,
        windowsHide: true,
    });
    const output = `${result.stdout ?? ''}\n${result.stderr ?? ''}`;

    if (result.error) throw result.error;
    if (result.status !== 0) {
        throw new Error(`${label} exited ${result.status}.\n${output}`);
    }
    if (forbiddenOutcome.test(output)) {
        throw new Error(`${label} reported an unexpected incomplete/skipped/pending/no-tests outcome.\n${output}`);
    }

    if (requirePhpSummary) {
        const tests = output.match(/Tests:\s*(\d+)/i) ?? output.match(/(?:OK|Tests:)\s*\((\d+) tests?/i);
        const assertions = output.match(/Assertions:\s*(\d+)/i) ?? output.match(/(?:,|\()\s*(\d+) assertions?\)/i);
        if (!tests || !assertions || Number(tests[1]) < 1 || Number(assertions[1]) < 1) {
            throw new Error(`${label} did not prove at least one executed test and assertion.\n${output}`);
        }
    }
    if (expectedMarker && !output.toLowerCase().includes(expectedMarker.toLowerCase())) {
        throw new Error(`${label} exited zero without its required success marker ${expectedMarker}.\n${output}`);
    }

    process.stdout.write(`[PAB-R3 PASS] ${label}\n`);
}

for (const [suite, file] of phpPure) {
    runPurePhpSuite(suite, file);
}
for (const suite of integration ? phpDb : []) {
    if (!existsSync(join(root, 'vendor', 'autoload.php'))) {
        throw new Error('Integration mode requires this checkout/worktree to have its own vendor/autoload.php.');
    }
    run(PHP_BINARY(), ['artisan', 'test', `--filter=${suite}`], suite, true);
}

for (const [file, marker] of nodeSuites) {
    run(process.execPath, [file], file, false, marker);
}

console.log(`PAB-R3 required-suite meta gate passed (${integration ? 'integration' : 'parallel-safe'} mode).`);

function runPurePhpSuite(suite, file) {
    if (existsSync(join(root, 'vendor', 'autoload.php'))) {
        run(PHP_BINARY(), ['artisan', 'test', `--filter=${suite}`], suite, true);
        return;
    }

    const externalAutoload = process.env.PAB_R3_VENDOR_AUTOLOAD;
    if (!externalAutoload || !existsSync(externalAutoload)) {
        throw new Error(`${suite} cannot run: local vendor/autoload.php is absent and PAB_R3_VENDOR_AUTOLOAD was not supplied.`);
    }
    const code = String.raw`
$loader = require $argv[1];
$root = $argv[2];
$loader->addPsr4('App\\', $root.'/app/', true);
$loader->addPsr4('Tests\\', $root.'/tests/', true);
chdir($root);
$runner = new PHPUnit\TextUI\Application();
exit($runner->run(['phpunit', '--no-configuration', $argv[3]]));
`;
    run(PHP_BINARY(), ['-r', code, externalAutoload, root, file], suite, true);
}

function PHP_BINARY() {
    return process.env.PHP_BINARY || (process.platform === 'win32' ? 'php.exe' : 'php');
}
