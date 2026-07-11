// SenseReviewSessionIdentityGuard.test.mjs
//
// ADR-0009: Review action transaction ledger and stack-based undo.
//
// Node built-in assert tests for the SenseReview session-identity helper.
// Guards the per-tab review session ID contract:
//   - Uses sessionStorage (NOT localStorage) — per-tab, not shared.
//   - UUID v4 format.
//   - Refresh-persistent (getOrCreate returns the same ID on second call).
//   - clearReviewSessionId removes the ID.
//   - No axios, no Vue dependency.
//
// Tests:
//   1.  File exists.
//   2.  Exports getOrCreateReviewSessionId, isValidReviewSessionId, clearReviewSessionId.
//   3.  Source uses sessionStorage (NOT localStorage).
//   4.  Source does NOT import axios.
//   5.  Source does NOT import Vue.
//   6.  isValidReviewSessionId accepts valid UUID v4.
//   7.  isValidReviewSessionId rejects non-UUID strings.
//   8.  isValidReviewSessionId rejects null/undefined/empty.
//   9.  isValidReviewSessionId rejects UUID v1/v3/v5 (version field != 4).
//  10.  getOrCreateReviewSessionId returns a valid UUID v4.
//  11.  getOrCreateReviewSessionId returns the same ID on second call (refresh persistence).
//  12.  clearReviewSessionId removes the stored ID.
//  13.  After clear, getOrCreateReviewSessionId generates a new different ID.
//  14.  No crypto.randomUUID call without fallback (guard manual fallback exists).

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const HELPER_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewSessionIdentity.js');

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

// Polyfill sessionStorage for Node.js (the helper uses it at runtime)
const sessionStore = new Map();
globalThis.sessionStorage = {
    getItem: (key) => sessionStore.has(key) ? sessionStore.get(key) : null,
    setItem: (key, value) => sessionStore.set(key, String(value)),
    removeItem: (key) => sessionStore.delete(key),
};

// Dynamic import for runtime tests
let helper;
try {
    helper = await import('file://' + HELPER_PATH + '?t=' + Date.now());
} catch (e) {
    helper = null;
}

// 1. File exists
test('file exists', () => {
    assert.ok(existsSync(HELPER_PATH), 'SenseReviewSessionIdentity.js must exist');
});

// 2. Exports
test('exports getOrCreateReviewSessionId, isValidReviewSessionId, clearReviewSessionId', () => {
    assert.ok(helper, 'module must import successfully');
    assert.strictEqual(typeof helper.getOrCreateReviewSessionId, 'function');
    assert.strictEqual(typeof helper.isValidReviewSessionId, 'function');
    assert.strictEqual(typeof helper.clearReviewSessionId, 'function');
});

// 3. Uses sessionStorage (NOT localStorage)
test('source uses sessionStorage, not localStorage', () => {
    assert.ok(source.includes('sessionStorage.getItem'), 'must use sessionStorage.getItem');
    assert.ok(source.includes('sessionStorage.setItem'), 'must use sessionStorage.setItem');
    // Must NOT use localStorage API calls (mentions in comments are fine)
    assert.ok(!source.includes('localStorage.getItem'), 'must NOT use localStorage.getItem');
    assert.ok(!source.includes('localStorage.setItem'), 'must NOT use localStorage.setItem');
    assert.ok(!source.includes('localStorage.removeItem'), 'must NOT use localStorage.removeItem');
});

// 4. No axios
test('source does NOT import axios', () => {
    // Check for actual import/usage, not just the string in comments
    assert.ok(!/import\s+.*axios/.test(source), 'must not import axios');
    assert.ok(!source.includes('axios.get'), 'must not call axios.get');
    assert.ok(!source.includes('axios.post'), 'must not call axios.post');
});

// 5. No Vue
test('source does NOT import Vue', () => {
    assert.ok(!/import\s+.*from\s+['"]vue['"]/.test(source), 'must not import from vue');
    assert.ok(!/Vue\.\w+/.test(source), 'must not reference Vue API');
});

// 6. isValidReviewSessionId accepts valid UUID v4
test('isValidReviewSessionId accepts valid UUID v4', () => {
    assert.ok(helper.isValidReviewSessionId('550e8400-e29b-41d4-a716-446655440000'));
    assert.ok(helper.isValidReviewSessionId('12345678-1234-4234-8234-123456789abc'));
});

// 7. Rejects non-UUID strings
test('isValidReviewSessionId rejects non-UUID strings', () => {
    assert.ok(!helper.isValidReviewSessionId('not-a-uuid'));
    assert.ok(!helper.isValidReviewSessionId('550e8400-e29b-41d4-a716'));
    assert.ok(!helper.isValidReviewSessionId('550e8400e29b41d4a716446655440000'));
});

// 8. Rejects null/undefined/empty
test('isValidReviewSessionId rejects null/undefined/empty', () => {
    assert.ok(!helper.isValidReviewSessionId(null));
    assert.ok(!helper.isValidReviewSessionId(undefined));
    assert.ok(!helper.isValidReviewSessionId(''));
    assert.ok(!helper.isValidReviewSessionId(123));
});

// 9. Rejects UUID v1/v3/v5
test('isValidReviewSessionId rejects non-v4 UUIDs', () => {
    // v1: version field = 1
    assert.ok(!helper.isValidReviewSessionId('550e8400-e29b-11d4-a716-446655440000'));
    // v5: version field = 5
    assert.ok(!helper.isValidReviewSessionId('550e8400-e29b-51d4-a716-446655440000'));
});

// 10. getOrCreateReviewSessionId returns valid UUID v4
test('getOrCreateReviewSessionId returns valid UUID v4', () => {
    sessionStore.clear();
    const id = helper.getOrCreateReviewSessionId();
    assert.ok(helper.isValidReviewSessionId(id), 'returned ID must be valid UUID v4: ' + id);
});

// 11. Returns same ID on second call (refresh persistence)
test('getOrCreateReviewSessionId returns same ID on second call', () => {
    sessionStore.clear();
    const id1 = helper.getOrCreateReviewSessionId();
    const id2 = helper.getOrCreateReviewSessionId();
    assert.strictEqual(id1, id2, 'second call must return the same ID (refresh persistence)');
});

// 12. clearReviewSessionId removes the stored ID
test('clearReviewSessionId removes the stored ID', () => {
    sessionStore.clear();
    const id = helper.getOrCreateReviewSessionId();
    helper.clearReviewSessionId();
    // After clear, sessionStorage should not have the old ID
    const stored = sessionStorage.getItem('sense_review_session_id');
    assert.ok(stored === null, 'sessionStorage must not have the ID after clear');
});

// 13. After clear, getOrCreate generates a new different ID
test('after clear, getOrCreate generates a new different ID', () => {
    sessionStore.clear();
    const id1 = helper.getOrCreateReviewSessionId();
    helper.clearReviewSessionId();
    const id2 = helper.getOrCreateReviewSessionId();
    assert.notStrictEqual(id1, id2, 'new ID must differ after clear');
    assert.ok(helper.isValidReviewSessionId(id2), 'new ID must be valid UUID v4');
});

// 14. Manual fallback exists (for environments without crypto.randomUUID)
test('source has manual UUID fallback', () => {
    assert.ok(source.includes('generateUuidV4'), 'must have generateUuidV4 helper');
    // Either uses crypto.randomUUID OR has getRandomValues fallback
    assert.ok(
        source.includes('crypto.randomUUID') || source.includes('crypto.getRandomValues'),
        'must use crypto.randomUUID or crypto.getRandomValues'
    );
});

console.log(`\n${passed} passed`);
