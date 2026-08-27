import assert from 'node:assert/strict';
import fs from 'node:fs';

const reader = fs.readFileSync('resources/js/components/TextReader/TextReader.vue', 'utf8');
const senses = fs.readFileSync('resources/js/components/Text/WordSensesList.vue', 'utf8');
const storeSource = fs.readFileSync('resources/js/vuex/VocabularyBox.js', 'utf8');
const storeModule = await import(`data:text/javascript;base64,${Buffer.from(storeSource).toString('base64')}`);

assert.match(reader, /syncCurrentVocabularyReadingContext\(\)/);
assert.match(reader, /saveReadingSessionRecoveryId\(this\.chapterId, normalized\.readingSessionId\);\s*this\.syncCurrentVocabularyReadingContext\(\)/);
assert.match(reader, /findReadingTargetForOpenedSelection\(this\.readingTargets, opened \|\| \{\}\)/);
assert.match(reader, /startWordIndex:\s*target\.start_word_index/);
assert.match(reader, /endWordIndex:\s*target\.end_word_index/);
assert.match(reader, /readingSessionId:\s*this\.readingSessionId/);
assert.match(reader, /sourceRevision:\s*this\.readingSourceRevision/);
assert.match(reader, /occurrenceId:\s*target\.occurrence_id/);
assert.match(reader, /readingSessionId:\s*null,[\s\S]*sourceRevision:\s*null,[\s\S]*occurrenceId:\s*null/);
assert.match(reader, /currentReadingSelectionFingerprint:\s*null/);
assert.match(reader, /let startWordIndex = Number\(this\.currentReadingSelectionFingerprint && this\.currentReadingSelectionFingerprint\.startWordIndex\)/);
assert.match(reader, /let endWordIndex = Number\(this\.currentReadingSelectionFingerprint && this\.currentReadingSelectionFingerprint\.endWordIndex\)/);
assert.match(reader, /this\.currentReadingSelectionFingerprint = hasSelectionFingerprint/);
assert.match(reader, /const selection = this\.\$refs\.interactiveText/);
assert.match(reader, /currentContext\.startWordIndex === startWordIndex/);
assert.match(reader, /currentContext\.endWordIndex === endWordIndex/);
assert.match(reader, /this\.onReaderOccurrenceOpened\(\{\s*start_word_index:\s*startWordIndex,\s*end_word_index:\s*endWordIndex/);
assert.match(reader, /\$store\.commit\('vocabularyBox\/setReadingContext', readingContext\)/);

const state = storeModule.default.state();
assert.equal(state.readingContext, null);
storeModule.default.mutations.setReadingContext(state, {
    startWordIndex: 14,
    endWordIndex: 14,
    readingSessionId: 'session-1',
    sourceRevision: 'revision-1',
    occurrenceId: 'occurrence-1',
});
assert.deepEqual(state.readingContext, {
    startWordIndex: 14,
    endWordIndex: 14,
    readingSessionId: 'session-1',
    sourceRevision: 'revision-1',
    occurrenceId: 'occurrence-1',
});
storeModule.default.mutations.reset(state);
assert.equal(state.readingContext, null, 'selection reset must clear stale Reader identity');

assert.match(senses, /readingContext:\s*state => state\.vocabularyBox\.readingContext/);
assert.match(senses, /readingContext:\s*this\.readingContext \? \{ \.\.\.this\.readingContext \} : null/);
assert.match(senses, /resolveCreateReadingContext\(\)/);
assert.match(senses, /currentContext\.startWordIndex === snapshotContext\.startWordIndex/);
assert.match(senses, /currentContext\.endWordIndex === snapshotContext\.endWordIndex/);
const payloadStart = senses.indexOf('createPayload(form)');
const payloadEnd = senses.indexOf('createSense()', payloadStart);
assert.ok(payloadStart > 0 && payloadEnd > payloadStart);
const payload = senses.slice(payloadStart, payloadEnd);
assert.match(payload, /const readingContext = this\.resolveCreateReadingContext\(\)/);
assert.match(payload, /if \(readingContext === false\) return null/);
assert.match(payload, /reading_session_id:\s*readingContext\?\.readingSessionId \|\| null/);
assert.match(payload, /source_revision:\s*readingContext\?\.sourceRevision \|\| null/);
assert.match(payload, /occurrence_id:\s*readingContext\?\.occurrenceId \|\| null/);
assert.match(payload, /chapter_id:\s*chapterId/);
const createStart = senses.indexOf('        createSense() {');
const createBlock = senses.slice(createStart, senses.indexOf('        saveEdit(', createStart));
assert.match(createBlock, /const payload = this\.createPayload\(this\.newForm\)/);
assert.match(createBlock, /if \(!payload\)/);
assert.match(createBlock, /阅读会话仍在初始化/);
assert.match(createBlock, /axios\.post\('\/senses\/manual', payload\)/);

console.log('Reader manual Sense identity guard passed.');
