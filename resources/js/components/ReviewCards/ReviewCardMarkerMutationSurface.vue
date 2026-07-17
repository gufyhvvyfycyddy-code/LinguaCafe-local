<template><div class="review-card-marker-mutation-surface" /></template>

<script>
import axios from 'axios';
import { bulkUpdateMarkers, updateMarker } from '../../services/ReviewCardMarkerApi.js';

export default {
    name: 'ReviewCardMarkerMutationSurface',
    data: () => ({ singleLoadingId: null, bulkLoading: false }),
    methods: {
        setMarker(item, marker) {
            if (!item || this.singleLoadingId !== null) return;
            this.singleLoadingId = item.review_card_id;
            updateMarker(axios, item.review_card_id, marker)
                .then(response => this.$emit('card-updated', response.data))
                .catch(error => this.$emit('error', error.response?.data?.message || '标记更新失败。'))
                .finally(() => { this.singleLoadingId = null; });
        },
        setBulkMarker(selection, marker) {
            if (!selection?.ids?.length || this.bulkLoading) return;
            this.bulkLoading = true;
            bulkUpdateMarkers(axios, selection.ids, marker)
                .then(response => this.$emit('bulk-updated', response.data))
                .catch(error => this.$emit('error', error.response?.data?.message || '批量标记失败。'))
                .finally(() => { this.bulkLoading = false; });
        },
    },
};
</script>
