export function buildReviewCardManageFilterState(vm) {
    return {
        q: vm.searchQuery || '',
        filter: vm.currentFilter || 'enabled',
        sort_by: vm.sortBy || 'id',
        sort_dir: vm.sortDir || 'desc',
        fsrs_states: [...(vm.advancedFilters?.fsrsStates || [])],
        due_range: vm.advancedFilters?.dueRange || 'all',
        reps_min: nullableNonNegativeInteger(vm.advancedFilters?.repsMin),
        lapses_min: nullableNonNegativeInteger(vm.advancedFilters?.lapsesMin),
        tag_ids: [...(vm.advancedFilters?.tagIds || [])]
            .map(Number)
            .filter(value => Number.isInteger(value) && value > 0)
            .sort((left, right) => left - right),
    };
}
export function applyReviewCardManageFilterState(vm, state) {
    vm.searchQuery = state.q || '';
    vm.currentFilter = state.filter || 'enabled';
    vm.activeFilter = vm.currentFilter;
    vm.sortBy = state.sort_by || 'id';
    vm.sortDir = state.sort_dir || 'desc';
    vm.advancedFilters = {
        fsrsStates: [...(state.fsrs_states || [])],
        dueRange: state.due_range || 'all',
        repsMin: state.reps_min ?? null,
        lapsesMin: state.lapses_min ?? null,
        tagIds: [...(state.tag_ids || [])].map(Number),
    };
}

function nullableNonNegativeInteger(value) {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isInteger(parsed) && parsed >= 0 ? parsed : value;
}
