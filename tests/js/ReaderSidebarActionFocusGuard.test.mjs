import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = fs.readFileSync(
    path.join(root, 'resources/js/components/Text/VocabularySideBox.vue'),
    'utf8',
);

test('keeps one inline pronunciation action and removes the duplicate header action', () => {
    assert.equal((source.match(/@click="textToSpeech"/g) || []).length, 1);
    assert.doesNotMatch(
        source,
        /v-if="tab == 0 && \$props\.textToSpeechAvailable" icon title="朗读"/,
    );
    assert.match(source, /v-if="\$props\.textToSpeechAvailable"[^>]+title="朗读"[^>]+aria-label="发音"/);
});

test('groups ordinary study-state actions and separates destructive recovery', () => {
    assert.match(source, /class="reader-study-state-actions[^"]*"/);
    assert.match(source, />学习状态</);
    assert.match(source, /class="reader-study-state-actions[\s\S]*@click="setStage\(1\)"[\s\S]*@click="setStage\(0\)"/);
    assert.match(source, /class="reader-recovery-action[^"]*"[\s\S]*@click="deleteWord"/);
    assert.match(source, /@click="deleteWord"[^>]*>[\s\S]*mdi-backup-restore[\s\S]*回归为新词/);
});

test('preserves existing action owners and the AI feature island', () => {
    assert.match(source, /@click="setStage\(1\)"/);
    assert.match(source, /@click="setStage\(0\)"/);
    assert.match(source, /@click="deleteWord"/);
    assert.match(source, /<AiStudyCardDesktopWorkflow ref="aiStudyCardWorkflow"/);
    assert.match(source, /deleteWord\(\) \{ this\.\$emit\('deleteWord'\); \}/);
});

test('gives candidate search a stable accessible focus target', () => {
    assert.match(source, /ref="dictionarySearchInput"/);
    assert.match(source, /aria-label="搜索词典"/);
    assert.match(source, /showAddSensePanel\(val\) \{[\s\S]*this\.showDictionaryResults = true;[\s\S]*this\.focusDictionarySearch\(\);/);
    assert.match(source, /focusDictionarySearch\(\) \{[\s\S]*this\.\$nextTick\(\(\) => \{[\s\S]*this\.\$refs\.dictionarySearchInput\.focus\(\);/);
});

test('does not add request or store ownership to the UI-only slice', () => {
    assert.equal((source.match(/axios\./g) || []).length, 0);
    assert.match(source, /@change="searchFieldChanged"/);
    assert.match(source, /:value="searchField"/);
});
