import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const goalSource = readFileSync(
    new URL('../../resources/js/components/Home/Goal.vue', import.meta.url),
    'utf8',
);
const calendarSource = readFileSync(
    new URL('../../resources/js/components/Home/Calendar.vue', import.meta.url),
    'utf8',
);

test('Home renders the canonical reading-new Sense progress copy', () => {
    assert.match(
        goalSource,
        /今天阅读新学\s*\{\{\s*todaysAchievedQuantity\s*\}\}\s*\/\s*\{\{\s*goalQuantity\s*\}\}\s*个词义/,
    );
});

test('Calendar keeps legacy learn_words achievement progress read-only', () => {
    assert.match(
        calendarSource,
        /:disabled="popupMenu\.saving\s*\|\|\s*achievement\.type\s*===\s*'learn_words'"/,
    );
});
