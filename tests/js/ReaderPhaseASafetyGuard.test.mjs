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
const settings = read('resources/js/services/LocalStorageManagerService.js');

test('ordinary lookup and explicit unfamiliar marking are separate branches', () => {
    const finishStart = block.indexOf('finishSelection: function()');
    const markBranch = block.indexOf('if (this.$props.unfamiliarMarkMode)', finishStart);
    const lookupSideEffect = block.indexOf('updateWordLookupCount', finishStart);
    assert.ok(finishStart >= 0 && markBranch > finishStart && lookupSideEffect > markBranch);
    assert.match(block, /\$emit\('mark-unfamiliar'/);
});

test('Phase A unfamiliar presentation never rewrites EncounteredWord stage', () => {
    assert.doesNotMatch(markPolicy, /EncounteredWord|stage\s*=|updateWordStage|saveWord|savePhrase/);
    assert.match(block, /reader-unfamiliar-target/);
});

test('AI source sends positional evidence and never sends client-owned sense authority', () => {
    assert.match(markPolicy, /marked_targets/);
    assert.match(markPolicy, /start_word_index/);
    assert.match(markPolicy, /end_word_index/);
    assert.doesNotMatch(markPolicy, /word_sense_id|user_id|language_id|ReviewLog|FSRS/);
});

test('Phase A AI and verification surfaces contain no formal rating writer', () => {
    for (const source of [assist, verification, markPolicy]) {
        assert.doesNotMatch(source, /ReviewLog|recordReview|FsrsScheduling|\/reviews\/senses\/\$\{|\/reviews\/rate|rating\s*:/);
    }
});

test('verification mutations are visibly disabled until the backend writer contract is integrated', () => {
    assert.match(verification, /resolutionEnabled:\s*\{\s*type:\s*Boolean,\s*default:\s*false\s*\}/);
    assert.match(verification, /本页不会假装保存成功/);
    assert.match(verification, /if \(!this\.resolutionEnabled\) return/);
    assert.match(reader, /:resolution-enabled="false"/);
    assert.doesNotMatch(reader, /reading-sense-resolution-intent/);
});

test('Trust AI and auto-add-new-sense preferences are separate, default false, and disabled until integration', () => {
    assert.match(settings, /trustAiReadingSenseBinding:\s*false/);
    assert.match(settings, /autoAddAiNewSenseToLearning:\s*false/);
    assert.match(settings, /trust-ai-reading-sense-binding/);
    assert.match(settings, /auto-add-ai-new-sense-to-learning/);
    assert.match(readerSettings, /v-model="settings\.trustAiReadingSenseBinding"[\s\S]{0,120}disabled/);
    assert.match(readerSettings, /v-model="settings\.autoAddAiNewSenseToLearning"[\s\S]{0,120}disabled/);
    assert.match(readerSettings, /正式后端绑定接口尚未在本 Lane 接通/);
});
