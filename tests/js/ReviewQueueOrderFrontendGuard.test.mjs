import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
    new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url),
    'utf8',
);

function extractMethod(name) {
    const re = new RegExp(name + '\\s*\\([^)]*\\)\\s*\\{');
    const match = source.match(re);
    assert.ok(match, `${name}() must exist in SenseReview.vue`);
    const start = match.index + match[0].length;
    let depth = 1;
    let i = start;
    while (i < source.length && depth > 0) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') depth--;
        i++;
    }
    return source.slice(match.index, i);
}

test('Sense Review currentCard is the queue head', () => {
    const currentCard = extractMethod('currentCard');
    assert.match(
        currentCard,
        /return\s+this\.cards\.length\s*\?\s*this\.cards\[0\]\s*:\s*null/,
        'currentCard must remain cards[0] when the queue is non-empty',
    );
});

test('Sense Review loadCards delegates queue loading to ReviewApiClient', () => {
    const loadCards = extractMethod('loadCards');
    assert.match(
        loadCards,
        /return\s+reviewApi\.loadSenseQueue\(params\)/,
        'loadCards must keep loading the queue through reviewApi.loadSenseQueue(params)',
    );
});

test('Sense Review preserves server order except navigation-history promotion', () => {
    const loadCards = extractMethod('loadCards');
    assert.match(loadCards, /const\s+cards\s*=\s*Array\.isArray\(response\.data\.cards\)\s*\?\s*\[\.\.\.response\.data\.cards\]/);
    assert.doesNotMatch(loadCards, /\.sort\(|Math\.random|shuffle/i);
    assert.match(loadCards, /const\s+preferredCardId\s*=\s*this\.navigationHistory\.currentCardId/);
    assert.match(loadCards, /cards\.splice\(preferredIndex,\s*1\)[\s\S]*cards\.unshift\(preferredCard\)/);
    assert.match(loadCards, /this\.cards\s*=\s*cards/);
});
