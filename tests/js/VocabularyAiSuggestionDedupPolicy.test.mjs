import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    buildAiSuggestionLookupKey,
    shouldSkipDuplicateAiSuggestionLookup,
} from '../../resources/js/services/VocabularyAiSuggestionService.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const boxSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/VocabularyBox.vue'), 'utf8');
const sideBoxSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/VocabularySideBox.vue'), 'utf8');

test('skips only an identical lookup that is still in flight', () => {
    const key = buildAiSuggestionLookupKey({
        chapterId: 2,
        sentenceIndex: 0,
        word: 'readers',
        lemma: 'reader',
    });

    assert.equal(shouldSkipDuplicateAiSuggestionLookup({
        currentLookupKey: key,
        nextLookupKey: key,
        isLoading: true,
    }), true);

    assert.equal(shouldSkipDuplicateAiSuggestionLookup({
        currentLookupKey: key,
        nextLookupKey: key,
        isLoading: false,
    }), false);

    assert.equal(shouldSkipDuplicateAiSuggestionLookup({
        currentLookupKey: key,
        nextLookupKey: key + '|new',
        isLoading: true,
    }), false);

    assert.equal(shouldSkipDuplicateAiSuggestionLookup({
        currentLookupKey: '',
        nextLookupKey: key,
        isLoading: true,
    }), false);

    assert.equal(shouldSkipDuplicateAiSuggestionLookup({
        currentLookupKey: key,
        nextLookupKey: '',
        isLoading: true,
    }), false);
});

test('both Reader vocabulary layouts apply the in-flight duplicate guard before fetching', () => {
    for (const [name, source] of [
        ['VocabularyBox', boxSource],
        ['VocabularySideBox', sideBoxSource],
    ]) {
        assert.match(source, /shouldSkipDuplicateAiSuggestionLookup/);
        const methodStart = source.indexOf('loadAiSuggestions()');
        assert.notEqual(methodStart, -1, name + ' must keep the AI lookup method');

        const method = source.slice(methodStart, source.indexOf('useAiSuggestion(', methodStart));
        const guardIndex = method.indexOf('shouldSkipDuplicateAiSuggestionLookup({');
        const assignIndex = method.indexOf('this.latestAiLookupKey = lookupKey;');
        const fetchIndex = method.indexOf('fetchAiSuggestions(axios, context)');

        assert.ok(guardIndex >= 0, name + ' must call the duplicate guard');
        assert.ok(assignIndex > guardIndex, name + ' must guard before replacing the active lookup key');
        assert.ok(fetchIndex > guardIndex, name + ' must guard before issuing the HTTP request');
        assert.match(method, /isLoading:\s*this\.aiLookupLoading/);
    }
});
