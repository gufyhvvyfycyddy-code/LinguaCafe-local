import assert from 'node:assert/strict';
import test from 'node:test';
import {
    applyReviewCardManageFilterState,
    buildReviewCardManageFilterState,
} from '../../resources/js/services/ReviewCardManageFilterState.js';

test('build emits the canonical backend shape', () => {
    const state = buildReviewCardManageFilterState({
        searchQuery: 'is:review hard',
        currentFilter: 'all',
        sortBy: 'fsrs_due_at',
        sortDir: 'asc',
        advancedFilters: { fsrsStates: ['review'], dueRange: 'today', repsMin: '2', lapsesMin: null },
    });

    assert.deepEqual(state, {
        q: 'is:review hard', filter: 'all', sort_by: 'fsrs_due_at', sort_dir: 'asc',
        fsrs_states: ['review'], due_range: 'today', reps_min: 2, lapses_min: null,
    });
});

test('apply replaces every filter and sort field without sharing arrays', () => {
    const vm = { advancedFilters: {} };
    const state = {
        q: 'word', filter: 'suspended', sort_by: 'fsrs_lapses', sort_dir: 'desc',
        fsrs_states: ['learning'], due_range: 'overdue', reps_min: 4, lapses_min: 2,
    };

    applyReviewCardManageFilterState(vm, state);
    state.fsrs_states.push('review');

    assert.equal(vm.searchQuery, 'word');
    assert.equal(vm.currentFilter, 'suspended');
    assert.equal(vm.activeFilter, 'suspended');
    assert.deepEqual(vm.advancedFilters.fsrsStates, ['learning']);
});
