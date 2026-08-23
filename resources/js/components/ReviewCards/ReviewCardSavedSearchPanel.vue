<script>
import axios from 'axios';

/**
 * Read-only compatibility adapter for old Browser saved-search deep links.
 * Persisted rows and lower CRUD APIs remain supported, but Saved Search is no
 * longer rendered as an ordinary user concept.
 */
export default {
    name: 'ReviewCardSavedSearchPanel',
    props: {
        language: { type: String, default: 'english' },
        initialSavedSearchId: { type: Number, default: null },
    },
    data() {
        return {
            requestGeneration: 0,
        };
    },
    watch: {
        language() {
            this.applyLegacyDeepLink();
        },
        initialSavedSearchId: {
            immediate: true,
            handler() {
                this.applyLegacyDeepLink();
            },
        },
    },
    methods: {
        async applyLegacyDeepLink() {
            const id = Number(this.initialSavedSearchId);
            const generation = ++this.requestGeneration;
            if (!Number.isInteger(id) || id <= 0) return;

            try {
                const response = await axios.get('/review-cards/manage/saved-searches');
                if (generation !== this.requestGeneration) return;
                const savedSearch = (response.data.items || []).find(item => Number(item.id) === id);
                if (savedSearch) {
                    this.$emit('apply', {
                        ...savedSearch,
                        filter_state: { ...savedSearch.filter_state },
                    });
                }
            } catch (error) {
                // Compatibility reads are non-blocking; the Browser keeps its
                // ordinary default filter when an old row is unavailable.
            }
        },
    },
    render(createElement) {
        return createElement();
    },
};
</script>
