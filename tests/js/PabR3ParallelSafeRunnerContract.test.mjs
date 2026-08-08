import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const runnerPath = join(root, 'tests', 'harness', 'run-pab-r3-required-suites.mjs');
const source = readFileSync(runnerPath, 'utf8');
const start = source.indexOf('function runPurePhpSuite');
const end = source.indexOf('\nfunction PHP_BINARY()', start);

assert.ok(start >= 0 && end > start, 'parallel-safe PHP runner function must remain discoverable');
const pureRunner = source.slice(start, end);

assert.match(pureRunner, /vendor[^\n]*autoload\.php/, 'parallel-safe mode must prefer the current worktree vendor when present');
assert.match(pureRunner, /PAB_R3_VENDOR_AUTOLOAD/, 'parallel-safe mode must retain the explicit external-autoload fallback');
assert.match(pureRunner, /PAB_R3_APP_ROOT/, 'parallel-safe mode must allow App\\ to come from an exact Backend candidate root');
assert.match(pureRunner, /addPsr4\('App\\\\', \$appRoot\.'\/app\/'/, 'parallel-safe mode must map App\\ to the selected Backend root');
assert.match(pureRunner, /addPsr4\('Tests\\\\', \$testsRoot\.'\/tests\/'/, 'parallel-safe mode must keep Tests\\ mapped to the Harness worktree');
assert.match(pureRunner, /--no-configuration/, 'parallel-safe mode must bypass project phpunit.xml and tests/bootstrap.php');
assert.doesNotMatch(pureRunner, /['"]artisan['"]\s*,\s*['"]test['"]/, 'parallel-safe mode must never route pure suites through artisan test');
assert.doesNotMatch(pureRunner, /tests\/bootstrap\.php/, 'parallel-safe mode must not load the project testing bootstrap');
assert.doesNotMatch(pureRunner, /TestingDatabaseLease/, 'parallel-safe mode must not acquire the machine-global testing lease directly');

console.log('PAB R3 parallel-safe runner contract passed');
