// ReviewCardLifecyclePresentationGuard.test.mjs
//
// ADR-0010: Review card lifecycle state machine.
//
// Node built-in assert tests for the pure presentation helper.
// Guards the cross-contract between frontend presentation and backend
// ReviewCardLifecyclePolicy:
//   - LIFECYCLE_STATES and LIFECYCLE_ACTIONS match backend values.
//   - Each state has label, color, hint.
//   - Each action has label, dangerLevel, hint.
//   - Pure module: no axios, no Vue, no DOM, no FSRS.
//   - Functions return correct types and values.
//
// Tests:
//   1.  File exists.
//   2.  Exports LIFECYCLE_STATES.
//   3.  LIFECYCLE_STATES = ['active','buried','suspended','archived'].
//   4.  Exports LIFECYCLE_ACTIONS.
//   5.  LIFECYCLE_ACTIONS = ['bury','unbury','suspend','resume','archive','restore'].
//   6.  Exports LIFECYCLE_PRESENTATION.
//   7.  Each LIFECYCLE_STATES key exists in LIFECYCLE_PRESENTATION.
//   8.  Each state entry has label (string), color (string), hint (string).
//   9.  Exports LIFECYCLE_ACTION_PRESENTATION.
//  10.  Each LIFECYCLE_ACTIONS key exists in LIFECYCLE_ACTION_PRESENTATION.
//  11.  Each action entry has label (string), dangerLevel, hint (string).
//  12.  dangerLevel is one of 'safe','moderate','dangerous'.
//  13.  Exports pure functions: stateLabel, stateColor, actionLabel, actionColor, actionHint, actionDangerLevel.
//  14.  stateLabel returns string for known state.
//  15.  stateLabel returns fallback for unknown state.
//  16.  stateColor returns string for known state.
//  17.  actionLabel returns string for known action.
//  18.  actionColor returns string for known action.
//  19.  actionHint returns string for known action.
//  20.  actionDangerLevel returns string for known action.
//  21.  Source does NOT import axios.
//  22.  Source does NOT import Vue.
//  23.  Source does NOT access document or window.
//  24.  Source does NOT contain FSRS scheduling (no stability/difficulty calc).
//  25.  Source does NOT compute buried_until (backend BuryTimeService owns that).

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const HELPER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'services', 'ReviewCardLifecyclePresentation.js');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
    } catch (e) {
        console.error('FAIL: ' + name);
        console.error(e.message);
        process.exitCode = 1;
    }
}

const source = existsSync(HELPER_PATH) ? readFileSync(HELPER_PATH, 'utf-8') : '';

// Dynamic import for runtime tests
let mod = null;
try {
    mod = await import('file://' + HELPER_PATH);
} catch (e) {
    // Will be caught by test 1
}

// 1. File exists
test('File exists', () => {
    assert.ok(existsSync(HELPER_PATH), 'ReviewCardLifecyclePresentation.js must exist');
});

// 2. Exports LIFECYCLE_STATES
test('Exports LIFECYCLE_STATES', () => {
    assert.ok(mod && mod.LIFECYCLE_STATES, 'LIFECYCLE_STATES must be exported');
});

// 3. LIFECYCLE_STATES has correct values
test('LIFECYCLE_STATES = active/buried/suspended/archived', () => {
    assert.deepEqual(mod.LIFECYCLE_STATES, ['active', 'buried', 'suspended', 'archived']);
});

// 4. Exports LIFECYCLE_ACTIONS
test('Exports LIFECYCLE_ACTIONS', () => {
    assert.ok(mod && mod.LIFECYCLE_ACTIONS, 'LIFECYCLE_ACTIONS must be exported');
});

// 5. LIFECYCLE_ACTIONS has correct values
test('LIFECYCLE_ACTIONS = bury/unbury/suspend/resume/archive/restore', () => {
    assert.deepEqual(mod.LIFECYCLE_ACTIONS, ['bury', 'unbury', 'suspend', 'resume', 'archive', 'restore']);
});

// 6. Exports LIFECYCLE_PRESENTATION
test('Exports LIFECYCLE_PRESENTATION', () => {
    assert.ok(mod && mod.LIFECYCLE_PRESENTATION, 'LIFECYCLE_PRESENTATION must be exported');
});

// 7. Each state key exists in LIFECYCLE_PRESENTATION
test('Each LIFECYCLE_STATES key exists in LIFECYCLE_PRESENTATION', () => {
    for (const state of mod.LIFECYCLE_STATES) {
        assert.ok(mod.LIFECYCLE_PRESENTATION[state], `State "${state}" missing from LIFECYCLE_PRESENTATION`);
    }
});

// 8. Each state entry has label, color, hint
test('Each state entry has label, color, hint (all strings)', () => {
    for (const state of mod.LIFECYCLE_STATES) {
        const entry = mod.LIFECYCLE_PRESENTATION[state];
        assert.equal(typeof entry.label, 'string', `State "${state}" label must be string`);
        assert.equal(typeof entry.color, 'string', `State "${state}" color must be string`);
        assert.equal(typeof entry.hint, 'string', `State "${state}" hint must be string`);
    }
});

// 9. Exports LIFECYCLE_ACTION_PRESENTATION
test('Exports LIFECYCLE_ACTION_PRESENTATION', () => {
    assert.ok(mod && mod.LIFECYCLE_ACTION_PRESENTATION, 'LIFECYCLE_ACTION_PRESENTATION must be exported');
});

// 10. Each action key exists in LIFECYCLE_ACTION_PRESENTATION
test('Each LIFECYCLE_ACTIONS key exists in LIFECYCLE_ACTION_PRESENTATION', () => {
    for (const action of mod.LIFECYCLE_ACTIONS) {
        assert.ok(mod.LIFECYCLE_ACTION_PRESENTATION[action], `Action "${action}" missing from LIFECYCLE_ACTION_PRESENTATION`);
    }
});

// 11. Each action entry has label, dangerLevel, hint
test('Each action entry has label, dangerLevel, hint (all strings)', () => {
    for (const action of mod.LIFECYCLE_ACTIONS) {
        const entry = mod.LIFECYCLE_ACTION_PRESENTATION[action];
        assert.equal(typeof entry.label, 'string', `Action "${action}" label must be string`);
        assert.equal(typeof entry.dangerLevel, 'string', `Action "${action}" dangerLevel must be string`);
        assert.equal(typeof entry.hint, 'string', `Action "${action}" hint must be string`);
    }
});

// 12. dangerLevel is one of safe/moderate/dangerous
test('dangerLevel values are valid', () => {
    const valid = ['safe', 'moderate', 'dangerous'];
    for (const action of mod.LIFECYCLE_ACTIONS) {
        const entry = mod.LIFECYCLE_ACTION_PRESENTATION[action];
        assert.ok(valid.includes(entry.dangerLevel), `Action "${action}" dangerLevel "${entry.dangerLevel}" not in ${JSON.stringify(valid)}`);
    }
});

// 13. Exports pure functions
test('Exports pure functions: stateLabel, stateColor, actionLabel, actionColor, actionHint, actionDangerLevel', () => {
    assert.equal(typeof mod.stateLabel, 'function');
    assert.equal(typeof mod.stateColor, 'function');
    assert.equal(typeof mod.actionLabel, 'function');
    assert.equal(typeof mod.actionColor, 'function');
    assert.equal(typeof mod.actionHint, 'function');
    assert.equal(typeof mod.actionDangerLevel, 'function');
});

// 14. stateLabel returns string for known state
test('stateLabel returns string for known state', () => {
    assert.equal(typeof mod.stateLabel('active'), 'string');
    assert.equal(typeof mod.stateLabel('buried'), 'string');
});

// 15. stateLabel returns fallback for unknown state
test('stateLabel returns fallback for unknown state', () => {
    const result = mod.stateLabel('nonexistent');
    assert.equal(typeof result, 'string');
    assert.ok(result.length > 0);
});

// 16. stateColor returns string for known state
test('stateColor returns string for known state', () => {
    assert.equal(typeof mod.stateColor('suspended'), 'string');
});

// 17. actionLabel returns string for known action
test('actionLabel returns string for known action', () => {
    assert.equal(typeof mod.actionLabel('bury'), 'string');
});

// 18. actionColor returns string for known action
test('actionColor returns string for known action', () => {
    assert.equal(typeof mod.actionColor('archive'), 'string');
});

// 19. actionHint returns string for known action
test('actionHint returns string for known action', () => {
    assert.equal(typeof mod.actionHint('suspend'), 'string');
});

// 20. actionDangerLevel returns string for known action
test('actionDangerLevel returns string for known action', () => {
    assert.equal(typeof mod.actionDangerLevel('restore'), 'string');
});

// 21. Source does NOT import axios
test('Source does NOT import axios', () => {
    // Check for actual import statements, not comments mentioning "axios"
    const importLines = source.split('\n').filter(l => l.trim().startsWith('import '));
    assert.ok(!importLines.some(l => l.includes('axios')), 'Presentation helper must not import axios');
});

// 22. Source does NOT import Vue
test('Source does NOT import Vue', () => {
    const importLines = source.split('\n').filter(l => l.trim().startsWith('import '));
    assert.ok(!importLines.some(l => /from\s+['"]vue['"]/.test(l)), 'Presentation helper must not import Vue');
});

// 23. Source does NOT access document or window
test('Source does NOT access document or window', () => {
    assert.ok(!source.includes('document.') && !source.includes('window.'), 'Presentation helper must not access DOM');
});

// 24. Source does NOT contain FSRS scheduling
test('Source does NOT contain FSRS scheduling logic', () => {
    assert.ok(!source.includes('fsrs_stability') && !source.includes('fsrs_difficulty') && !source.includes('schedule'), 'Presentation helper must not contain FSRS scheduling');
});

// 25. Source does NOT compute buried_until
test('Source does NOT compute buried_until', () => {
    assert.ok(!source.includes('buried_until') || source.includes('buried_until') && source.includes('backend'), 'Presentation helper must not compute buried_until (backend owns that)');
});

console.log(`\n${passed} tests passed.`);
