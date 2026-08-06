<template>
    <div class="word-sense-tag-manager">
        <div class="d-flex align-center" style="gap: 8px;">
            <v-autocomplete
                :value="selectedTagIds"
                :items="tags"
                item-text="name"
                item-value="id"
                label="WordSense 标签（全部匹配）"
                prepend-inner-icon="mdi-tag-multiple-outline"
                multiple
                chips
                small-chips
                deletable-chips
                clearable
                dense
                :hide-details="!loadError"
                :loading="loading"
                :error-messages="loadError ? [loadError] : []"
                @change="$emit('selection-change', $event || [])"
            />
            <v-btn small text color="primary" @click="dialog = true">
                <v-icon small left>mdi-tag-edit-outline</v-icon>管理标签
            </v-btn>
        </div>

        <v-dialog v-model="dialog" max-width="620">
            <v-card>
                <v-card-title>管理 WordSense 标签</v-card-title>
                <v-card-text>
                    <v-alert v-if="error" type="error" dense text>{{ error }}</v-alert>
                    <div class="d-flex align-center mb-3" style="gap: 8px;">
                        <v-text-field
                            v-model="newName"
                            label="新标签名称（可用 :: 表示层级）"
                            dense
                            hide-details
                            maxlength="80"
                            @keyup.enter="createTag"
                        />
                        <v-btn color="primary" small :loading="creating" :disabled="!newName.trim()" @click="createTag">创建</v-btn>
                    </div>
                    <v-progress-linear v-if="loading" indeterminate class="mb-2" />
                    <v-alert v-else-if="tags.length === 0" type="info" dense text>还没有标签。</v-alert>
                    <v-list v-else dense>
                        <v-list-item v-for="tag in tags" :key="tag.id">
                            <v-list-item-icon><v-icon small>mdi-tag-outline</v-icon></v-list-item-icon>
                            <v-list-item-content>
                                <v-text-field
                                    v-if="editingId === tag.id"
                                    v-model="editingName"
                                    dense
                                    hide-details
                                    maxlength="80"
                                    @keyup.enter="saveRename(tag)"
                                />
                                <template v-else>
                                    <v-list-item-title>{{ tag.name }}</v-list-item-title>
                                    <v-list-item-subtitle>{{ tag.senses_count || 0 }} 个词义</v-list-item-subtitle>
                                </template>
                            </v-list-item-content>
                            <v-list-item-action class="d-flex flex-row">
                                <template v-if="editingId === tag.id">
                                    <v-btn icon small :loading="savingId === tag.id" @click="saveRename(tag)">
                                        <v-icon small>mdi-check</v-icon>
                                    </v-btn>
                                    <v-btn icon small @click="cancelRename"><v-icon small>mdi-close</v-icon></v-btn>
                                </template>
                                <template v-else>
                                    <v-btn icon small @click="startRename(tag)"><v-icon small>mdi-pencil</v-icon></v-btn>
                                    <v-btn icon small color="error" :loading="deletingId === tag.id" @click="deleteTag(tag)">
                                        <v-icon small>mdi-delete-outline</v-icon>
                                    </v-btn>
                                </template>
                            </v-list-item-action>
                        </v-list-item>
                    </v-list>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="dialog = false">关闭</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import axios from 'axios';

export default {
    props: {
        selectedTagIds: { type: Array, default: () => [] },
        refreshKey: { type: Number, default: 0 },
    },
    data() {
        return {
            tags: [],
            loading: false,
            loadError: '',
            error: '',
            dialog: false,
            newName: '',
            creating: false,
            editingId: null,
            editingName: '',
            savingId: null,
            deletingId: null,
        };
    },
    watch: {
        refreshKey() {
            this.loadTags();
        },
    },
    mounted() {
        this.loadTags();
    },
    methods: {
        async loadTags() {
            this.loading = true;
            this.loadError = '';
            try {
                const response = await axios.get('/review-cards/manage/tags');
                this.tags = response.data.items || [];
                this.$emit('catalog-change', this.tags);
            } catch (error) {
                this.loadError = error.response?.data?.message || '标签加载失败。';
            } finally {
                this.loading = false;
            }
        },
        async createTag() {
            const name = this.newName.trim();
            if (!name || this.creating) return;
            this.creating = true;
            this.error = '';
            try {
                await axios.post('/review-cards/manage/tags', { name });
                this.newName = '';
                await this.loadTags();
                this.$emit('notify', '标签已创建。', 'success');
            } catch (error) {
                this.error = this.errorMessage(error, '标签创建失败。');
            } finally {
                this.creating = false;
            }
        },
        startRename(tag) {
            this.editingId = tag.id;
            this.editingName = tag.name;
            this.error = '';
        },
        cancelRename() {
            this.editingId = null;
            this.editingName = '';
        },
        async saveRename(tag) {
            const name = this.editingName.trim();
            if (!name || this.savingId) return;
            this.savingId = tag.id;
            this.error = '';
            try {
                await axios.patch(`/review-cards/manage/tags/${tag.id}`, { name });
                this.cancelRename();
                await this.loadTags();
                this.$emit('catalog-mutated');
                this.$emit('notify', '标签已重命名。', 'success');
            } catch (error) {
                this.error = this.errorMessage(error, '标签重命名失败。');
            } finally {
                this.savingId = null;
            }
        },
        async deleteTag(tag) {
            if (this.deletingId || !window.confirm(`删除标签“${tag.name}”？词义和复习卡不会被删除。`)) return;
            this.deletingId = tag.id;
            this.error = '';
            try {
                await axios.delete(`/review-cards/manage/tags/${tag.id}`);
                const selected = this.selectedTagIds.filter(id => id !== tag.id);
                if (selected.length !== this.selectedTagIds.length) this.$emit('selection-change', selected);
                await this.loadTags();
                this.$emit('catalog-mutated');
                this.$emit('notify', '标签已删除。', 'success');
            } catch (error) {
                this.error = this.errorMessage(error, '标签删除失败。');
            } finally {
                this.deletingId = null;
            }
        },
        errorMessage(error, fallback) {
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
