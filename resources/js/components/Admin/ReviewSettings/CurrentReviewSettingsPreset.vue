<template>
    <v-alert dense outlined type="info" class="mt-4 mb-0">
        <strong>当前 Preset：{{ presetName }}</strong>
        <span class="mx-2">·</span>
        <span>当前语言：{{ currentLanguage }}</span>
    </v-alert>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    props: {
        language: {
            type: String,
            default: '',
        },
    },
    data() {
        return {
            loading: true,
            metadata: null,
        };
    },
    computed: {
        presetName() {
            return this.metadata?.name || (this.loading ? '加载中…' : '不可用');
        },
        currentLanguage() {
            return this.metadata?.language || this.language || '—';
        },
    },
    mounted() {
        AdminReviewSettingsApi.getPresetMetadata()
            .then(response => { this.metadata = response.data.reviewSettingsPresetMetadata; })
            .catch(() => {})
            .finally(() => { this.loading = false; });
    },
};
</script>
