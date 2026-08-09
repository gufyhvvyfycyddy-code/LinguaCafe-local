import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = path => fs.readFileSync(path, 'utf8');
const block = read('resources/js/components/Text/TextBlockGroup.vue');
const assist = read('resources/js/components/TextReader/TextReaderAiAssist.vue');
const verification = read('resources/js/components/TextReader/ReadingSenseVerificationDialog.vue');
const reader = read('resources/js/components/TextReader/TextReader.vue');
const readerSettings = read('resources/js/components/TextReader/TextReaderSettings.vue');
const markPolicy = read('resources/js/services/ReaderUnfamiliarTargetPolicy.js');
const recoveryPolicy = read('resources/js/services/ReaderRecoveryPolicy.js');
const settings = read('resources/js/services/LocalStorageManagerService.js');

test('ordinary lookup and explicit unfamiliar marking remain separate branches', () => {
    const finishStart = block.indexOf('finishSelection: function()');
    const markBranch = block.indexOf('if (this.$props.unfamiliarMarkMode)', finishStart);
    const lookupSideEffect = block.indexOf('updateWordLookupCount', finishStart);
    assert.ok(finishStart >= 0 && markBranch > finishStart && lookupSideEffect > markBranch);
    assert.match(block, /\$emit\('mark-unfamiliar'/);
    assert.match(block, /\$emit\('reader-occurrence-opened'/);
});

test('unfamiliar presentation never rewrites EncounteredWord stage', () => {
    assert.doesNotMatch(markPolicy, /EncounteredWord|stage\s*=|updateWordStage|saveWord|savePhrase/);
    assert.match(block, /reader-unfamiliar-target/);
});

test('AI source sends positional evidence with server snapshot freshness and no client-owned sense authority', () => {
    assert.match(markPolicy, /marked_targets/);
    assert.match(markPolicy, /start_word_index/);
    assert.match(markPolicy, /end_word_index/);
    assert.doesNotMatch(markPolicy, /word_sense_id|user_id|language_id|ReviewLog|FSRS/);
    assert.match(assist, /markedTargetsSnapshotVersion/);
    assert.match(assist, /marked_targets_snapshot_version/);
    assert.match(assist, /服务器标记快照尚未加载/);
});

test('AI and verification surfaces do not invent a second formal rating writer', () => {
    for (const source of [assist, verification, markPolicy]) {
        assert.doesNotMatch(source, /ReviewLog|recordReview|FsrsScheduling|\/reviews\/senses\/\$\{|\/reviews\/rate|rating\s*:/);
    }
});

test('R3 verification writes only through the dedicated evidence endpoint and is gated by a server reading session', () => {
    assert.match(verification, /resolutionEnabled:\s*\{\s*type:\s*Boolean,\s*default:\s*false\s*\}/);
    assert.match(verification, /if \(!this\.resolutionEnabled\) return/);
    assert.match(reader, /:resolution-enabled="Boolean\(readingSessionId\)"/);
    assert.match(reader, /reading-occurrence-evidence/);
    assert.match(reader, /resolution:\s*intent\.resolution/);
    assert.doesNotMatch(reader, /ReviewLog::|FsrsScheduling|recordReview\(/);
});

test('Trust AI is opt-in for future persisted evidence while auto-add-new-sense stays disabled', () => {
    assert.match(settings, /trustAiReadingSenseBinding:\s*false/);
    assert.match(settings, /autoAddAiNewSenseToLearning:\s*false/);
    assert.match(settings, /trust-ai-reading-sense-binding/);
    assert.match(settings, /auto-add-ai-new-sense-to-learning/);
    assert.doesNotMatch(readerSettings, /v-model="settings\.trustAiReadingSenseBinding"[\s\S]{0,100}disabled/);
    assert.match(readerSettings, /v-model="settings\.autoAddAiNewSenseToLearning"[\s\S]{0,100}disabled/);
    assert.match(readerSettings, /后续生成或重新确认/);
    assert.match(readerSettings, /不会追溯修改旧结果/);
    assert.match(readerSettings, /不会自动评分/);
});

test('Reader recovery policy cannot mint a reading-session id', () => {
    assert.doesNotMatch(recoveryPolicy, /reading-session-["'`]?\s*\+|randomUUID\(\).*reading|sessionId\s*=\s*createReaderRequestId/);
    assert.match(recoveryPolicy, /saveReadingSessionRecoveryId/);
    assert.match(reader, /resume_reading_session_id/);
    assert.match(reader, /reading_session_id/);
});

test('Phase A Finish uses the legacy-compatible non-settlement request', () => {
    const finishStart = reader.indexOf('finish() {');
    const finishEnd = reader.indexOf('preflightFinishSettlement() {', finishStart);
    const finishMethod = reader.slice(finishStart, finishEnd);

    assert.ok(finishStart >= 0 && finishEnd > finishStart);
    assert.match(finishMethod, /const basePayload = this\.buildFinishBasePayload\(\)/);
    assert.match(finishMethod, /axios\.post\('\/chapters\/finish', basePayload\)/);
    assert.doesNotMatch(finishMethod, /readingSessionId|settlement_mode|buildReaderFinishRequest|preFinishSafetyCheck/);
    assert.match(reader, /仍待核对的词义不会阻止完成/);
    assert.match(reader, /不会因为完成阅读而提交词义评分或改变复习计划/);
});
