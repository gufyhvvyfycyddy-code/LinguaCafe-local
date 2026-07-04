<template>
    <v-container id="reading-inline-confirmation-manage">
        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex align-center">
                阅读中词义确认记录
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">共 {{ pagination.total || 0 }} 条</v-chip>
            </div>

            <!-- Safety banner: this is NOT a review rating -->
            <v-alert
                dense
                text
                type="info"
                icon="mdi-information-outline"
                class="mt-2 mb-4 inline-confirmation-safety-banner"
            >
                这些记录来自阅读页的"是这个意思 / 不是这个意思"。
                <strong class="inline-confirmation-not-review-rating">这不是复习评分</strong>，
                不会写入复习记录，不会改变复习进度（FSRS）。
                撤销一条记录只表示删除这条阅读中判断证据，
                <strong class="inline-confirmation-not-forget">不是忘记，不是复习失败，也不是删除词义</strong>。
            </v-alert>

            <!-- Filters -->
            <v-row dense>
                <v-col cols="12" md="2">
                    <v-select
                        dense
                        filled
                        rounded
                        hide-details
                        label="选择"
                        :items="choiceFilters"
                        v-model="filters.choice"
                        @change="reload"
                    ></v-select>
                </v-col>
                <v-col cols="12" md="2">
                    <v-text-field
                        dense
                        filled
                        rounded
                        hide-details
                        label="词元"
                        v-model="filters.lemma"
                        @keyup.enter="reload"
                    ></v-text-field>
                </v-col>
                <v-col cols="12" md="2">
                    <v-text-field
                        dense
                        filled
                        rounded
                        hide-details
                        label="词形"
                        v-model="filters.surface"
                        @keyup.enter="reload"
                    ></v-text-field>
                </v-col>
                <v-col cols="12" md="2">
                    <v-text-field
                        dense
                        filled
                        rounded
                        hide-details
                        label="文章 ID"
                        v-model="filters.chapter_id"
                        @keyup.enter="reload"
                    ></v-text-field>
                </v-col>
                <v-col cols="12" md="2" class="d-flex">
                    <v-btn depressed rounded color="primary" class="mr-2" @click="reload">
                        <v-icon left>mdi-refresh</v-icon>刷新
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Empty state -->
            <div v-if="!loading && !items.length" class="text-center text--secondary pa-8 inline-confirmation-empty">
                <v-icon large color="grey lighten-1">mdi-format-list-bulleted</v-icon>
                <div class="mt-2">暂无阅读中词义确认记录。</div>
                <div class="text-caption mt-1">在阅读页点开候选预览面板，点击"是这个意思 / 不是这个意思"即可生成记录。</div>
            </div>

            <!-- List -->
            <v-list v-else class="mt-2">
                <v-list-item
                    v-for="item in items"
                    :key="item.confirmation_id"
                    class="inline-confirmation-row px-0"
                    :class="{ 'is-match': item.choice === 'match', 'is-not-match': item.choice === 'not_match' }"
                >
                    <v-list-item-content>
                        <div class="d-flex align-center flex-wrap mb-1">
                            <v-chip
                                x-small
                                :color="item.choice === 'match' ? 'success' : 'error'"
                                class="mr-2 inline-confirmation-choice-chip"
                            >
                                <span v-if="item.choice === 'match'" class="inline-confirmation-match-label">是这个意思</span>
                                <span v-else class="inline-confirmation-not-match-label">不是这个意思</span>
                            </v-chip>
                            <strong class="default-font mr-2 inline-confirmation-surface">{{ item.surface }}</strong>
                            <span class="text--secondary mr-1">词元：</span>
                            <strong class="default-font mr-2 inline-confirmation-lemma">{{ item.lemma }}</strong>
                            <v-chip v-if="item.pos" x-small outlined class="mr-2">{{ item.pos }}</v-chip>
                            <v-spacer></v-spacer>
                            <span class="text-caption text--secondary inline-confirmation-updated">
                                {{ formatTime(item.updated_at) }}
                            </span>
                        </div>
                        <div v-if="item.sense_zh || item.sense_en" class="text-body-2 mb-1 inline-confirmation-sense-summary">
                            <span v-if="item.sense_zh" class="mr-2">{{ item.sense_zh }}</span>
                            <span v-if="item.sense_en" class="text--secondary">{{ item.sense_en }}</span>
                        </div>
                        <div v-if="item.sentence_text" class="text-caption text--secondary mb-1 inline-confirmation-sentence">
                            句子：<span class="default-font">{{ item.sentence_text }}</span>
                        </div>
                        <div class="d-flex align-center flex-wrap text-caption text--secondary">
                            <v-icon x-small class="mr-1">mdi-book-open-outline</v-icon>
                            <span class="inline-confirmation-chapter-name">{{ item.chapter_name || ('章节 #' + item.chapter_id) }}</span>
                            <v-spacer></v-spacer>
                            <v-btn
                                v-if="item.chapter_id"
                                x-small
                                text
                                color="primary"
                                class="mr-2 inline-confirmation-back-to-reading"
                                @click="goToChapter(item.chapter_id)"
                            >
                                <v-icon left x-small>mdi-arrow-left</v-icon>
                                回到阅读页
                            </v-btn>
                            <v-btn
                                x-small
                                text
                                color="error"
                                class="inline-confirmation-revoke-btn"
                                @click="openRevokeDialog(item)"
                            >
                                <v-icon left x-small>mdi-undo</v-icon>
                                撤销这条阅读判断
                            </v-btn>
                        </div>
                    </v-list-item-content>
                </v-list-item>
            </v-list>

            <!-- Pagination -->
            <div v-if="pagination.last_page > 1" class="text-center mt-4">
                <v-pagination
                    v-model="pagination.current_page"
                    :length="pagination.last_page"
                    @input="onPageChange"
                ></v-pagination>
            </div>
        </v-card>

        <!-- Revoke confirmation dialog -->
        <v-dialog v-model="revokeDialog.open" max-width="520">
            <v-card>
                <v-card-title class="text-h6">撤销这条阅读中确认记录？</v-card-title>
                <v-card-text>
                    <v-alert dense text type="info" icon="mdi-information-outline" class="mb-3 inline-confirmation-revoke-alert">
                        <div>撤销只删除这条阅读中判断证据。</div>
                        <div class="mt-1">
                            <strong class="inline-confirmation-revoke-not-forget">不是忘记，不是复习失败，不是删除词义。</strong>
                        </div>
                        <div class="mt-1">不会写入复习记录，不会改变复习进度（FSRS）。</div>
                    </v-alert>
                    <div v-if="revokeDialog.item" class="text-body-2">
                        <div>词形：<strong>{{ revokeDialog.item.surface }}</strong></div>
                        <div>词元：<strong>{{ revokeDialog.item.lemma }}</strong></div>
                        <div>选择：
                            <strong v-if="revokeDialog.item.choice === 'match'" class="inline-confirmation-revoke-match-label">是这个意思</strong>
                            <strong v-else class="inline-confirmation-revoke-not-match-label">不是这个意思</strong>
                        </div>
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="closeRevokeDialog">取消</v-btn>
                    <v-btn
                        depressed
                        color="error"
                        class="inline-confirmation-revoke-confirm"
                        :loading="revokeDialog.loading"
                        @click="confirmRevoke"
                    >
                        确认撤销
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Undo snackbar: shown after a revoke action
             (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1) -->
        <v-snackbar
            v-model="undoSnackbar.show"
            :timeout="undoSnackbar.timeout"
            :color="undoSnackbar.color"
            class="inline-confirmation-undo-snackbar"
        >
            <span class="inline-confirmation-undo-hint">{{ undoSnackbar.text }}</span>
            <template v-slot:action="{ attrs }">
                <v-btn
                    text
                    v-bind="attrs"
                    @click="triggerUndo"
                    class="inline-confirmation-undo-btn"
                >
                    撤销
                </v-btn>
            </template>
        </v-snackbar>
    </v-container>
</template>

<script>
import axios from 'axios';

/**
 * ReadingInlineConfirmationManage
 *
 * (GLM-ReadingInlineConfirmationManagementSurface-1000-1, ADR-0003 Management Surface Layer)
 *
 * A lightweight management page for the current user's reading-inline
 * sense confirmations. Allows:
 *  - listing / filtering by choice / lemma / surface / chapter;
 *  - viewing the source sentence + WordSense summary + chapter name;
 *  - jumping back to the reading page (`/chapters/read/{chapter_id}`);
 *  - revoking (deleting) a single confirmation row.
 *
 * Safety contract:
 *  - Revoke ONLY calls `DELETE /senses/inline-confirmations/{id}`.
 *  - Does NOT call any review rating route.
 *  - Does NOT call any ReviewLog route.
 *  - Does NOT call any FSRS route.
 *  - Does NOT call any AI route.
 *  - Does NOT batch-revoke.
 *  - Copy strictly avoids "删除词义" / "复习失败" / "忘记了".
 */
export default {
    name: 'ReadingInlineConfirmationManage',
    data() {
        return {
            loading: false,
            items: [],
            pagination: {
                current_page: 1,
                per_page: 20,
                total: 0,
                last_page: 1,
            },
            filters: {
                choice: 'all',
                lemma: '',
                surface: '',
                chapter_id: '',
            },
            choiceFilters: [
                { text: '全部', value: 'all' },
                { text: '是这个意思', value: 'match' },
                { text: '不是这个意思', value: 'not_match' },
            ],
            revokeDialog: {
                open: false,
                loading: false,
                item: null,
            },
            // Undo token (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1):
            // opaque backend-signed string returned by DELETE
            // /senses/inline-confirmations/{id}. Only the most recent revoke
            // is undoable. Cleared after use.
            undoToken: null,
            undoSnackbar: {
                show: false,
                text: '',
                color: 'info',
                timeout: 6000,
            },
            undoLoading: false,
        };
    },
    mounted() {
        this.reload();
        window.addEventListener('keydown', this.handleKeyDown);
    },
    beforeDestroy() {
        window.removeEventListener('keydown', this.handleKeyDown);
    },
    methods: {
        reload() {
            this.loading = true;
            const params = {
                page: this.pagination.current_page,
                per_page: this.pagination.per_page,
                choice: this.filters.choice,
            };
            if (this.filters.lemma) params.lemma = this.filters.lemma;
            if (this.filters.surface) params.surface = this.filters.surface;
            if (this.filters.chapter_id) params.chapter_id = this.filters.chapter_id;

            axios.get('/senses/inline-confirmations', { params })
                .then(response => {
                    const data = response.data || {};
                    this.items = data.data || [];
                    this.pagination = data.pagination || this.pagination;
                })
                .catch(() => {
                    this.items = [];
                    this.pagination.total = 0;
                    this.pagination.last_page = 1;
                })
                .finally(() => {
                    this.loading = false;
                });
        },
        onPageChange(page) {
            this.pagination.current_page = page;
            this.reload();
        },
        goToChapter(chapterId) {
            window.location.href = '/chapters/read/' + chapterId;
        },
        openRevokeDialog(item) {
            this.revokeDialog.open = true;
            this.revokeDialog.loading = false;
            this.revokeDialog.item = item;
        },
        closeRevokeDialog() {
            this.revokeDialog.open = false;
            this.revokeDialog.loading = false;
            this.revokeDialog.item = null;
        },
        confirmRevoke() {
            if (!this.revokeDialog.item) return;
            this.revokeDialog.loading = true;
            axios.delete('/senses/inline-confirmations/' + this.revokeDialog.item.confirmation_id)
                .then((response) => {
                    const data = response && response.data;
                    this.items = this.items.filter(i => i.confirmation_id !== this.revokeDialog.item.confirmation_id);
                    this.pagination.total = Math.max(0, this.pagination.total - 1);
                    this.closeRevokeDialog();

                    // Save the undo token returned by the backend and show
                    // a snackbar telling the user they can press Ctrl+Z
                    // (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1).
                    if (data && typeof data.undo_token === 'string' && data.undo_token) {
                        this.undoToken = data.undo_token;
                        this.undoSnackbar = {
                            show: true,
                            text: data.undo_hint || '按 Ctrl+Z 可恢复。',
                            color: 'info',
                            timeout: 6000,
                        };
                    }
                })
                .catch(() => {
                    // leave dialog open so user can retry
                })
                .finally(() => {
                    this.revokeDialog.loading = false;
                });
        },
        /**
         * Keydown handler for Ctrl+Z / Cmd+Z (Anki-style undo).
         * - If focus is inside an input / textarea / select / contenteditable,
         *   do NOT intercept — let the browser do native text undo.
         * - If no undo token is available, do nothing (no error).
         * - Otherwise, call the undo endpoint to restore the revoked row.
         *
         * (OpenCode-ReadingInlineConfirmationUndoHotkey-800-1, ADR-0003 Undo Hotkey Layer)
         */
        handleKeyDown(event) {
            const isCtrlOrCmd = event.ctrlKey || event.metaKey;
            if (!isCtrlOrCmd || event.key !== 'z' && event.key !== 'Z') {
                return;
            }
            if (this.isFocusInsideEditableInput()) {
                return;
            }
            if (!this.undoToken || this.undoLoading) {
                return;
            }
            event.preventDefault();
            this.triggerUndo();
        },
        isFocusInsideEditableInput() {
            const el = document.activeElement;
            if (!el) return false;
            const tag = (el.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') {
                return true;
            }
            if (el.isContentEditable) {
                return true;
            }
            return false;
        },
        triggerUndo() {
            if (!this.undoToken || this.undoLoading) {
                return;
            }
            this.undoLoading = true;
            axios.post('/senses/inline-confirmations/undo', {
                undo_token: this.undoToken,
            }).then(() => {
                // Token consumed — clear it so it cannot be replayed.
                this.undoToken = null;
                this.undoSnackbar = {
                    show: true,
                    text: '已恢复刚才撤销的阅读判断。',
                    color: 'success',
                    timeout: 4000,
                };
                // Refresh the list so the restored row reappears.
                this.reload();
            }).catch(() => {
                // Token invalid / expired / cross-user / cross-language.
                this.undoToken = null;
                this.undoSnackbar = {
                    show: true,
                    text: '撤销失败：撤销令牌已过期或无效。',
                    color: 'error',
                    timeout: 4000,
                };
            }).finally(() => {
                this.undoLoading = false;
            });
        },
        formatTime(iso) {
            if (!iso) return '';
            try {
                const d = new Date(iso);
                return d.toLocaleString();
            } catch (e) {
                return iso;
            }
        },
    },
};
</script>

<style scoped>
.inline-confirmation-row {
    border-left: 3px solid transparent;
}
.inline-confirmation-row.is-match {
    border-left-color: var(--v-success-base, #4caf50);
}
.inline-confirmation-row.is-not-match {
    border-left-color: var(--v-error-base, #f44336);
}
.inline-confirmation-safety-banner {
    background-color: rgba(var(--v-theme-info, 33, 150, 243), 0.05);
}
</style>
