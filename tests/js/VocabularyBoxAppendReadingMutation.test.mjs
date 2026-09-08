import test from 'node:test';
import assert from 'node:assert/strict';

import vocabularyBox from '../../resources/js/vuex/VocabularyBox.js';

// #62: the appendReading mutation wrote to a non-existent `state.appendReading`
// property instead of the real `state.reading` field (the one consumed by the
// vocabulary box save path). It must accumulate onto state.reading, matching its
// sibling appendSearchField (state.searchField += value).

test('appendReading appends onto state.reading', () => {
    const state = vocabularyBox.state();
    assert.equal(state.reading, '', 'reading starts empty');

    vocabularyBox.mutations.appendReading(state, 'ka');
    vocabularyBox.mutations.appendReading(state, 'ki');

    assert.equal(state.reading, 'kaki', 'readings accumulate onto state.reading');
});

test('appendReading does not create a stray state.appendReading field', () => {
    const state = vocabularyBox.state();
    vocabularyBox.mutations.appendReading(state, 'x');

    assert.equal(
        Object.prototype.hasOwnProperty.call(state, 'appendReading'),
        false,
        'no stray state.appendReading property is created',
    );
    assert.equal(state.reading, 'x');
});

test('appendReading matches its sibling appendSearchField behaviour', () => {
    const state = vocabularyBox.state();
    vocabularyBox.mutations.appendSearchField(state, 'go');
    vocabularyBox.mutations.appendReading(state, 'go');
    assert.equal(state.searchField, 'go');
    assert.equal(state.reading, 'go');
});
