<template>
    <v-card outlined class="saved-search-panel mb-3">
        <v-card-text class="py-3">
            <div class="d-flex flex-wrap align-center" style="gap: 8px;">
                <div class="d-flex align-center mr-2">
                    <v-icon small color="primary" class="mr-2">mdi-content-save-search</v-icon>
                    <span class="font-weight-medium">保存的搜索</span>
                    <v-chip v-if="appliedId" x-small outlined color="primary" class="ml-2">已应用</v-chip>
                </div>

                <v-select
                    v-model="selectedId"
                    :items="searches"
                    item-text="name"
                    item-value="id"
                    label="选择搜索"
                    dense
                    outlined
                    hide-details
                    clearable
                    :loading="loading"
                    class="saved-search-select"
                    @change="syncSelectedName"
                />
                <v-btn small color="primary" outlined :disabled="!selectedSearch" @click="applySelected">
                    <v-icon small left>mdi-play</v-icon>应用
                </v-btn>

                <v-text-field
                    v-model="name"
                    label="搜索名称"
                    dense
                    outlined
                    hide-details
                    maxlength="80"
                    class="saved-search-name"
                    @keyup.enter="saveCurrent"
                />
                <v-btn small color="primary" :loading="saving" :disabled="!trimmedName" @click="saveCurrent">
                    <v-icon small left>mdi-content-save-plus</v-icon>保存当前
                </v-btn>
                <v-btn small text :loading="saving" :disabled="!selectedSearch || !trimmedName" @click="updateSelected">
                    更新
                </v-btn>
                <v-btn small icon color="error" :disabled="!selectedSearch || saving" title="删除保存的搜索" @click="deleteDialog = true">
                    <v-icon small>mdi-delete-outline</v-icon>
                </v-btn>
            </div>

            <v-alert v-if="error" type="error" dense text class="mt-3 mb-0">{{ error }}</v-alert>
            <div class="text-caption text--secondary mt-2">
                保存的是当前筛选与排序；应用后手动调整不会自动覆盖保存内容。
            </div>
        </v-card-text>

        <v-dialog v-model="deleteDialog" max-width="420">
            <v-card>
                <v-card-title>删除保存的搜索？</v-card-title>
                <v-card-text>“{{ selectedSearch ? selectedSearch.name : '' }}”将被永久删除，但不会影响任何复习卡。</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="deleteDialog = false">取消</v-btn>
                    <v-btn color="error" :loading="saving" @click="deleteSelected">删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-card>
</template>

<script>
import axios from 'axios';

export default {
    props: {
        filterState: { type: Object, required: true },
        language: { type: String, default: 'english' },
    },
    data() {
        return {
            searches: [],
            selectedId: null,
            appliedId: null,
            name: '',
            loading: false,
            saving: false,
            error: '',
            deleteDialog: false,
            requestGeneration: 0,
        };
    },
    computed: {
        selectedSearch() {
            return this.searches.find(search => search.id === this.selectedId) || null;
        },
        trimmedName() {
            return this.name.trim();
        },
    },
    watch: {
        language() {
            this.selectedId = null;
            this.appliedId = null;
            this.name = '';
            this.loadSearches();
        },
    },
    mounted() {
        this.loadSearches();
    },
    methods: {
        async loadSearches() {
            const generation = ++this.requestGeneration;
            this.loading = true;
            this.error = '';
            try {
                const response = await axios.get('/review-cards/manage/saved-searches');
                if (generation !== this.requestGeneration) return;
                this.searches = response.data.items || [];
                if (this.selectedId && !this.selectedSearch) {
                    this.selectedId = null;
                    this.appliedId = null;
                }
            } catch (error) {
                if (generation === this.requestGeneration) this.error = this.messageFor(error, '保存的搜索加载失败。');
            } finally {
                if (generation === this.requestGeneration) this.loading = false;
            }
        },
        syncSelectedName() {
            this.name = this.selectedSearch ? this.selectedSearch.name : '';
        },
        applySelected() {
            if (!this.selectedSearch) return;
            this.appliedId = this.selectedSearch.id;
            this.$emit('apply', { ...this.selectedSearch, filter_state: { ...this.selectedSearch.filter_state } });
        },
        async saveCurrent() {
            if (!this.trimmedName || this.saving) return;
            await this.mutate('post', '/review-cards/manage/saved-searches', {
                name: this.trimmedName,
                filter_state: this.filterState,
            });
        },
        async updateSelected() {
            if (!this.selectedSearch || !this.trimmedName || this.saving) return;
            await this.mutate('patch', `/review-cards/manage/saved-searches/${this.selectedSearch.id}`, {
                name: this.trimmedName,
                filter_state: this.filterState,
            });
        },
        async deleteSelected() {
            if (!this.selectedSearch || this.saving) return;
            this.saving = true;
            this.error = '';
            try {
                await axios.delete(`/review-cards/manage/saved-searches/${this.selectedSearch.id}`);
                this.selectedId = null;
                this.appliedId = null;
                this.name = '';
                this.deleteDialog = false;
                await this.loadSearches();
            } catch (error) {
                this.error = this.messageFor(error, '删除失败。');
            } finally {
                this.saving = false;
            }
        },
        async mutate(method, url, payload) {
            this.saving = true;
            this.error = '';
            try {
                const response = await axios[method](url, payload);
                await this.loadSearches();
                this.selectedId = response.data.id;
                this.appliedId = response.data.id;
                this.syncSelectedName();
            } catch (error) {
                this.error = this.messageFor(error, '保存失败。');
            } finally {
                this.saving = false;
            }
        },
        messageFor(error, fallback) {
            const errors = error.response?.data?.errors;
            if (errors) {
                const first = Object.values(errors).flat()[0];
                if (first) return first;
            }
            return error.response?.data?.message || fallback;
        },
    },
};
</script>

<style scoped>
.saved-search-panel {
    border-color: rgba(25, 118, 210, 0.24) !important;
    background: linear-gradient(90deg, rgba(25, 118, 210, 0.045), transparent 55%);
}
.saved-search-select { flex: 1 1 220px; max-width: 340px; }
.saved-search-name { flex: 1 1 180px; max-width: 280px; }
@media (max-width: 600px) {
    .saved-search-select, .saved-search-name { max-width: none; flex-basis: 100%; }
}
</style>
