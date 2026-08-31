import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const read = (...parts) => readFileSync(join(process.cwd(), ...parts), 'utf8');

const scheduler = read('.github', 'workflows', 'auto-fix-scheduler.yml');
const executor = read('.github', 'workflows', 'opencode-executor.yml');

const schedulerInputs = new Set(
  [...scheduler.matchAll(/^      ([A-Za-z0-9_-]+):\r?\n        description:/gm)].map((match) => match[1]),
);
assert.deepEqual(schedulerInputs, new Set(['issue_number', 'attempt']));

const schedulerCall = executor.match(
  /gh workflow run auto-fix-scheduler\.yml[\s\S]*?--field attempt=\$ATTEMPT/,
)?.[0] ?? '';
const passedInputs = new Set(
  [...schedulerCall.matchAll(/--field ([A-Za-z0-9_-]+)=/g)].map((match) => match[1]),
);
assert.deepEqual(passedInputs, schedulerInputs);

const executorPermissionBlock = executor.match(/permissions:[\s\S]*?steps:/)?.[0] ?? '';
assert.match(executorPermissionBlock, /actions: write/);

const schedulerJobEnv = scheduler.match(/    env:[\s\S]*?    steps:/)?.[0] ?? '';
assert.match(schedulerJobEnv, /GH_TOKEN: \$\{\{ secrets\.GITHUB_TOKEN \}\}/);

const attemptStep = executor.match(/- name: Determine attempt number[\s\S]*?(?=\n      - name:)/)?.[0] ?? '';
assert.match(attemptStep, /GH_TOKEN: \$\{\{ secrets\.GITHUB_TOKEN \}\}/);

const createPrStep = executor.match(/- name: Create Pull Request[\s\S]*?(?=\n      - name:)/)?.[0] ?? '';
assert.match(createPrStep, /GH_TOKEN: \$\{\{ secrets\.GITHUB_TOKEN \}\}/);

const schedulerTriggerStep = executor.match(/- name: Trigger auto-fix scheduler on failure[\s\S]*$/)?.[0] ?? '';
assert.match(schedulerTriggerStep, /GH_TOKEN: \$\{\{ secrets\.GITHUB_TOKEN \}\}/);
assert.match(schedulerTriggerStep, /--field issue_number=/);
assert.match(schedulerTriggerStep, /--field attempt=\$ATTEMPT/);
assert.doesNotMatch(schedulerTriggerStep, /--field repository=/);

assert.match(scheduler, /NEXT=\$\(\(PREV \+ 1\)\)/);
assert.match(executor, /--field attempt=\$ATTEMPT/);

console.log('Auto-fix workflow contract guard passed.');
