<template>
    <v-container id="sense-review">
        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex align-center">
                词义复习
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">到期数量 {{ summary.due_count || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">已复习 {{ reviewedCount }}</v-chip>
                <v-chip class="mx-1" color="foreground">剩余 {{ remainingCount }}</v-chip>
                <v-chip class="mx-1 my-1" small outlined>今日已复习 {{ fsrsStats.reviewed_today }}</v-chip>
                <v-btn icon small @click="statsDetailOpen = !statsDetailOpen">
                    <v-icon>{{ statsDetailOpen ? 'mdi-chevron-up' : 'mdi-chart-box-outline' }}</v-icon>
                </v-btn>
            </div>
            <v-expand-transition>
                <div v-if="statsDetailOpen" class="d-flex flex-wrap align-center pb-2">
                    <v-chip class="mx-1 my-1" small outlined>今日重置 {{ fsrsStats.reset_count }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>总词义卡 {{ fsrsStats.total }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>启用中 {{ fsrsStats.enabled }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>已归档 {{ fsrsStats.archived }}</v-chip>
                    <v-chip class="mx-1 my-1" small outlined>当前到期 {{ fsrsStats.due }}</v-chip>
                </div>
            </v-expand-transition>
            <v-alert v-if="statsError" type="warning" dense text class="mt-2 mb-0">{{ statsError }}</v-alert>
        </v-card>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

        <v-card v-if="currentCard" outlined class="rounded-lg pa-5">
            <!-- Lemma / surface form / pos — always visible -->
            <div class="d-flex align-center mb-3">
                <div>
                    <div class="text-h5 default-font">{{ currentCard.lemma }}</div>
                    <div class="text--secondary">
                        {{ currentCard.surface_form || currentCard.lemma }}
                        <span v-if="currentCard.pos"> / {{ currentCard.pos }}</span>
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-chip class="mr-1">{{ currentCard.fsrs_state }}</v-chip>
                <v-chip>{{ currentCard.fsrs_reps }} 次</v-chip>
            </div>

            <!-- Question side (always visible, shows context) -->
            <div class="mb-4">
                <div class="caption text--secondary">例句</div>
                <v-sheet outlined rounded class="pa-3 mb-3">
                    <div class="default-font">{{ currentCard.example_sentence_en || '暂无例句。' }}</div>
                </v-sheet>
                <div class="body-1 primary--text font-weight-medium">
                    这个句子里的 “{{ currentCard.lemma }}” 是什么意思？
                </div>
            </div>

            <!-- Show answer button (visible when showAnswer=false) -->
            <div v-if="!showAnswer" class="d-flex justify-center mb-4">
                <v-btn
                    depressed
                    rounded
                    color="primary"
                    large
                    :disabled="rating || archiveLoading || deleteLoading || resetLoading"
                    @click="showAnswer = true"
                >
                    显示答案
                </v-btn>
            </div>

            <div class="text-center caption grey--text mt-2" v-if="!showAnswer">
                快捷键：Space 显示答案
            </div>

            <!-- Answer side (visible when showAnswer=true) -->
            <template v-if="showAnswer">
                <!-- Action buttons in More menu -->
                <div class="d-flex justify-end mb-3" style="gap: 8px;">
                    <v-menu offset-y left>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn small text v-bind="attrs" v-on="on">
                                <v-icon small left>mdi-dots-vertical</v-icon>更多
                            </v-btn>
                        </template>
                        <v-list dense>
                            <v-list-item @click="viewSource">
                                <v-list-item-icon><v-icon small>mdi-book-open-page-variant</v-icon></v-list-item-icon>
                                <v-list-item-title>查看原文</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="startEdit">
                                <v-list-item-icon><v-icon small>mdi-pencil</v-icon></v-list-item-icon>
                                <v-list-item-title>编辑</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="openArchiveDialog">
                                <v-list-item-icon><v-icon small color="warning">mdi-archive</v-icon></v-list-item-icon>
                                <v-list-item-title>归档</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="openResetDialog">
                                <v-list-item-icon><v-icon small>mdi-restore</v-icon></v-list-item-icon>
                                <v-list-item-title>重置</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="openDeleteDialog">
                                <v-list-item-icon><v-icon small color="error">mdi-delete</v-icon></v-list-item-icon>
                                <v-list-item-title class="error--text">彻底删除</v-list-item-title>
                            </v-list-item>
                        </v-list>
                    </v-menu>
                </div>

                <v-row dense>
                    <v-col cols="12" md="6">
                        <div class="caption text--secondary">中文释义</div>
                        <div class="sense-main mb-4">{{ currentCard.sense_zh }}</div>

                        <div class="caption text--secondary">英文释义</div>
                        <div class="mb-4">{{ currentCard.sense_en || '暂无英文释义。' }}</div>

                        <div class="caption text--secondary">近义译法</div>
                        <div class="mb-4">
                            <v-chip small class="mr-1 mb-1" v-for="alias in currentCard.aliases_zh" :key="alias">{{ alias }}</v-chip>
                            <span v-if="!currentCard.aliases_zh.length" class="text--secondary">无</span>
                        </div>

                        <div class="caption text--secondary">搭配</div>
                        <div>
                            <v-chip small class="mr-1 mb-1" v-for="collocation in currentCard.collocations" :key="collocation">{{ collocation }}</v-chip>
                            <span v-if="!currentCard.collocations.length" class="text--secondary">无</span>
                        </div>
                    </v-col>
                    <v-col cols="12" md="6">
                        <div class="caption text--secondary">例句</div>
                        <v-sheet outlined rounded class="pa-3 mb-4">
                            <div class="default-font">{{ currentCard.example_sentence_en || '暂无例句。' }}</div>
                            <div class="text--secondary mt-2">{{ currentCard.example_sentence_zh }}</div>
                        </v-sheet>

                        <div class="caption text--secondary d-flex align-center" style="cursor: pointer;" @click="fsrsDetailOpen = !fsrsDetailOpen">
                            FSRS：到期 {{ currentCard.fsrs_due_at || '-' }}
                            <v-icon small class="ml-1">{{ fsrsDetailOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                        </div>
                        <v-expand-transition>
                            <div v-if="fsrsDetailOpen">
                                <v-simple-table dense class="no-hover border rounded-lg mt-2">
                                    <tbody>
                                        <tr><td>稳定度</td><td>{{ currentCard.fsrs_stability || '-' }}</td></tr>
                                        <tr><td>难度</td><td>{{ currentCard.fsrs_difficulty || '-' }}</td></tr>
                                        <tr><td>遗忘次数</td><td>{{ currentCard.fsrs_lapses }}</td></tr>
                                    </tbody>
                                </v-simple-table>
                            </div>
                        </v-expand-transition>
                    </v-col>
                </v-row>

                <div class="text-center caption grey--text mb-2">
                    快捷键：1 忘了 / 2 勉强 / 3 记得 / 4 很熟
                </div>

                <!-- Score buttons -->
                <div class="d-flex justify-center flex-wrap mt-6">
                    <v-btn depressed rounded color="error" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('again')">忘了</v-btn>
                    <v-btn depressed rounded color="warning" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('hard')">勉强记得</v-btn>
                    <v-btn depressed rounded color="primary" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('good')">记得</v-btn>
                    <v-btn depressed rounded color="success" class="ma-2" :disabled="rating || archiveLoading || deleteLoading || resetLoading" @click="rate('easy')">很熟</v-btn>
                </div>
            </template>
        </v-card>

        <v-alert v-else-if="!loading" type="info" dense outlined>
            当前没有到期词义卡。
        </v-alert>

        <!-- Edit dialog -->
        <v-dialog v-model="editDialog" max-width="600">
            <v-card>
                <v-card-title>编辑词义卡片</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="6">
                            <v-text-field
                                v-model="editForm.pos"
                                label="词性"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="6">
                            <v-text-field
                                v-model="editForm.sense_zh"
                                label="中文释义"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.sense_en"
                                label="英文释义"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea
                                v-model="editForm.example_sentence_en"
                                label="英文例句"
                                dense
                                hide-details="auto"
                                rows="2"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.example_sentence_zh"
                                label="中文例句"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.aliases_zh_text"
                                label="近义译法（逗号分隔）"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="editForm.collocations_text"
                                label="搭配（逗号分隔）"
                                dense
                                hide-details="auto"
                                class="mb-3"
                            />
                        </v-col>
                    </v-row>
                    <v-alert v-if="editError" type="error" dense outlined class="mt-2">{{ editError }}</v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="cancelEdit">取消</v-btn>
                    <v-btn color="primary" :loading="editing" @click="saveEdit">保存</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Archive confirmation dialog -->
        <v-dialog v-model="archiveDialog" max-width="480">
            <v-card>
                <v-card-title>确认归档</v-card-title>
                <v-card-text>
                    归档后，这张词义卡不会进入日常复习，但释义、例句、复习历史都会保留。确定归档吗？
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="archiveDialog = false" :disabled="archiveLoading">取消</v-btn>
                    <v-btn color="warning" :loading="archiveLoading" @click="archiveCard">确认归档</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Reset confirmation dialog -->
        <v-dialog v-model="resetDialog" max-width="500">
            <v-card>
                <v-card-title>重置为新学卡</v-card-title>
                <v-card-text>
                    <p>这会清空这张词义卡的 FSRS 记忆状态，并把它重新设为新学卡。</p>
                    <p>复习历史会保留，释义、例句和原文位置不会改变。</p>
                    <p>重置后，这张卡会立即重新进入复习队列。</p>
                    <p class="font-weight-bold">确定重置吗？</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="resetDialog = false" :disabled="resetLoading">取消</v-btn>
                    <v-btn color="primary" :loading="resetLoading" @click="resetCard">确认重置</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete confirmation dialog -->
        <v-dialog v-model="deleteDialog" max-width="480">
            <v-card>
                <v-card-title>确认删除</v-card-title>
                <v-card-text>
                    这会删除这张词义复习卡，并让该释义不再出现在阅读页点词结果中。阅读材料、原文位置和复习历史会保留。此操作不可恢复。确定删除吗？
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="deleteDialog = false" :disabled="deleteLoading">取消</v-btn>
                    <v-btn color="error" :loading="deleteLoading" @click="deleteCard">确认删除</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Source context dialog -->
        <sense-example-dialog
            v-model="sourceDialog"
            :payload="sourcePayload"
            language="english"
            :font-size="16"
        />

        <!-- Snackbar -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000" top>
            {{ snackbar.text }}
            <template #action="{ attrs }">
                <v-btn text v-bind="attrs" @click="snackbar.show = false">关闭</v-btn>
            </template>
        </v-snackbar>
    </v-container>
</template>

<script>
    import SenseExampleDialog from '../Review/SenseExampleDialog.vue';

    export default {
        components: {
            SenseExampleDialog,
        },
        data: function() {
            return {
                loading: false,
                rating: false,
                error: '',
                cards: [],
                summary: {},
                reviewedCount: 0,
                // Edit dialog
                editDialog: false,
                editing: false,
                editError: '',
                editForm: {
                    pos: '',
                    sense_zh: '',
                    sense_en: '',
                    example_sentence_en: '',
                    example_sentence_zh: '',
                    aliases_zh_text: '',
                    collocations_text: '',
                },
                // Archive dialog
                archiveDialog: false,
                archiveLoading: false,
                // Reset dialog
                resetDialog: false,
                resetLoading: false,
                // Delete dialog
                deleteDialog: false,
                deleteLoading: false,
                // Source context dialog
                sourceDialog: false,
                sourcePayload: {},
                // Snackbar
                snackbar: {
                    show: false,
                    text: '',
                    color: 'success',
                },
                // FSRS stats
                statsLoading: false,
                statsError: '',
                fsrsStats: {
                    total: 0,
                    enabled: 0,
                    archived: 0,
                    due: 0,
                    by_state: {
                        new: 0,
                        learning: 0,
                        review: 0,
                        relearning: 0,
                    },
                    average_stability: null,
                    average_difficulty: null,
                    lapses_total: 0,
                    reviewed_today: 0,
                    reset_count: 0,
                },
                // UI-Review-a
                statsDetailOpen: false,
                fsrsDetailOpen: false,
                showAnswer: false,
            }
        },
        computed: {
            currentCard() {
                return this.cards.length ? this.cards[0] : null;
            },
            remainingCount() {
                return this.cards.length;
            },
        },
        beforeDestroy() {
            window.removeEventListener('keyup', this.handleHotkey);
        },
        mounted() {
            this.loadCards();
            this.loadFsrsStats();
            window.addEventListener('keyup', this.handleHotkey);
        },
        methods: {
            loadFsrsStats() {
                this.statsLoading = true;
                this.statsError = '';
                axios.get('/review-cards/stats')
                    .then((response) => {
                        this.fsrsStats = response.data;
                    })
                    .catch(() => {
                        this.statsError = 'FSRS 统计加载失败。';
                    })
                    .finally(() => {
                        this.statsLoading = false;
                    });
            },
            loadCards() {
                this.loading = true;
                this.error = '';
                axios.get('/reviews/senses').then((response) => {
                    this.cards = response.data.cards;
                    this.summary = response.data.summary;
                    this.fsrsDetailOpen = false;  // Reset FSRS collapse on card change
                    this.showAnswer = false;
                }).catch((error) => {
                    this.error = error.response?.data?.message || '词义复习队列加载失败。';
                }).finally(() => {
                    this.loading = false;
                });
            },
            rate(rating) {
                if (!this.currentCard) {
                    return;
                }

                this.rating = true;
                this.error = '';
                axios.post(`/reviews/senses/${this.currentCard.review_card_id}/rate`, {
                    rating: rating,
                }).then((response) => {
                    this.reviewedCount++;
                    this.summary = response.data.summary;
                    this.loadCards();
                    this.loadFsrsStats();
                }).catch((error) => {
                    this.error = error.response?.data?.message || '词义卡评分失败。';
                }).finally(() => {
                    this.rating = false;
                });
            },
            // UI-Review-c: keyboard shortcuts
            handleHotkey(event) {
                // Ignore when typing in input/textarea/select
                const tag = event.target?.tagName?.toLowerCase();
                if (['input', 'textarea', 'select'].includes(tag) || event.target?.isContentEditable) {
                    return;
                }
                // Ignore when dialogs are open
                if (this.editDialog || this.archiveDialog || this.resetDialog || this.deleteDialog || this.sourceDialog) {
                    return;
                }
                // Ignore when no card or loading
                if (!this.currentCard || this.loading || this.rating || this.archiveLoading || this.resetLoading || this.deleteLoading) {
                    return;
                }
                switch (event.key) {
                    case ' ':
                    case 'Spacebar':
                        event.preventDefault();
                        if (!this.showAnswer) {
                            this.showAnswer = true;
                        }
                        break;
                    case '1':
                        if (this.showAnswer) { this.rate('again'); }
                        break;
                    case '2':
                        if (this.showAnswer) { this.rate('hard'); }
                        break;
                    case '3':
                        if (this.showAnswer) { this.rate('good'); }
                        break;
                    case '4':
                        if (this.showAnswer) { this.rate('easy'); }
                        break;
                }
            },
            // ==================== Edit dialog ====================
            startEdit() {
                if (!this.currentCard) {
                    return;
                }

                this.editError = '';
                this.editForm = {
                    pos: this.currentCard.pos || '',
                    sense_zh: this.currentCard.sense_zh || '',
                    sense_en: this.currentCard.sense_en || '',
                    example_sentence_en: this.currentCard.example_sentence_en || '',
                    example_sentence_zh: this.currentCard.example_sentence_zh || '',
                    aliases_zh_text: Array.isArray(this.currentCard.aliases_zh)
                        ? this.currentCard.aliases_zh.join(', ')
                        : '',
                    collocations_text: Array.isArray(this.currentCard.collocations)
                        ? this.currentCard.collocations.join(', ')
                        : '',
                };
                this.editDialog = true;
            },
            saveEdit() {
                if (!this.currentCard) {
                    return;
                }

                this.editing = true;
                this.editError = '';

                // Build payload: normalize comma-separated text fields to arrays
                const payload = {
                    pos: this.editForm.pos,
                    sense_zh: this.editForm.sense_zh,
                    sense_en: this.editForm.sense_en,
                    example_sentence_en: this.editForm.example_sentence_en,
                    example_sentence_zh: this.editForm.example_sentence_zh,
                    aliases_zh: this.editForm.aliases_zh_text
                        .split(',')
                        .map(s => s.trim())
                        .filter(s => s !== ''),
                    collocations: this.editForm.collocations_text
                        .split(',')
                        .map(s => s.trim())
                        .filter(s => s !== ''),
                };

                axios.patch(`/review-cards/manage/${this.currentCard.review_card_id}`, payload)
                    .then((response) => {
                        // Update current card with saved data
                        const saved = response.data;
                        this.cards[0].pos = saved.pos;
                        this.cards[0].sense_zh = saved.sense_zh;
                        this.cards[0].sense_en = saved.sense_en;
                        this.cards[0].example_sentence_en = saved.example_sentence_en;
                        this.cards[0].example_sentence_zh = saved.example_sentence_zh;
                        this.cards[0].aliases_zh = saved.aliases_zh || [];
                        this.cards[0].collocations = saved.collocations || [];
                        // Force reactivity
                        this.cards = [...this.cards];
                        this.editDialog = false;
                        this.showSnackbar('已保存词义卡片。', 'success');
                    })
                    .catch((err) => {
                        this.editError = err.response?.data?.message || '词义卡片保存失败。';
                    })
                    .finally(() => {
                        this.editing = false;
                    });
            },
            cancelEdit() {
                this.editDialog = false;
                this.editError = '';
            },
            // ==================== Source context dialog ====================
            viewSource() {
                if (!this.currentCard) {
                    return;
                }

                const card = {
                    lemma: this.currentCard.lemma,
                    surface_form: this.currentCard.surface_form,
                    sense_zh: this.currentCard.sense_zh,
                    sense_en: this.currentCard.sense_en,
                    example_sentence_en: this.currentCard.example_sentence_en,
                    example_sentence_zh: this.currentCard.example_sentence_zh,
                };

                axios.get(`/senses/${this.currentCard.word_sense_id}/source-context`)
                    .then((response) => {
                        this.sourcePayload = { card: card, context: response.data };
                        this.sourceDialog = true;
                    })
                    .catch(() => {
                        this.sourcePayload = { card: card, context: null, error: '获取原文失败。' };
                        this.sourceDialog = true;
                    });
            },
            // ==================== Archive ====================
            openArchiveDialog() {
                if (!this.currentCard) {
                    return;
                }
                this.archiveDialog = true;
            },
            archiveCard() {
                if (!this.currentCard) {
                    return;
                }

                this.archiveLoading = true;
                axios.patch(`/review-cards/manage/${this.currentCard.review_card_id}/enabled`, {
                    enabled: false,
                }).then(() => {
                    this.archiveDialog = false;
                    this.showSnackbar('已归档。该卡不会进入日常复习。', 'success');
                    this.loadCards();
                    this.loadFsrsStats();
                }).catch((err) => {
                    this.showSnackbar(err.response?.data?.message || '归档失败。', 'error');
                }).finally(() => {
                    this.archiveLoading = false;
                });
            },
            // ==================== Reset ====================
            openResetDialog() {
                if (!this.currentCard) {
                    return;
                }
                this.resetDialog = true;
            },
            resetCard() {
                if (!this.currentCard) {
                    return;
                }

                this.resetLoading = true;
                axios.post(`/review-cards/manage/${this.currentCard.review_card_id}/reset`)
                    .then((response) => {
                        this.resetDialog = false;
                        this.showSnackbar(response.data?.message || '已重置为新学卡。该卡会重新进入复习队列。', 'success');
                        this.loadCards();
                        this.loadFsrsStats();
                    })
                    .catch((err) => {
                        this.showSnackbar(err.response?.data?.message || '重置失败。', 'error');
                    })
                    .finally(() => {
                        this.resetLoading = false;
                    });
            },
            // ==================== Delete ====================
            openDeleteDialog() {
                if (!this.currentCard) {
                    return;
                }
                this.deleteDialog = true;
            },
            deleteCard() {
                if (!this.currentCard) {
                    return;
                }

                this.deleteLoading = true;
                axios.delete(`/review-cards/manage/${this.currentCard.review_card_id}`)
                    .then((response) => {
                        this.deleteDialog = false;
                        const message = response.data?.message || '已彻底删除词义复习卡。';
                        this.showSnackbar(message, 'success');
                        this.loadCards();
                        this.loadFsrsStats();
                    })
                    .catch((err) => {
                        this.showSnackbar(err.response?.data?.message || '删除失败。', 'error');
                    })
                    .finally(() => {
                        this.deleteLoading = false;
                    });
            },
            // ==================== Snackbar ====================
            showSnackbar(text, color) {
                this.snackbar = { show: true, text, color };
            },
        }
    }
</script>

<style scoped>
    .sense-main {
        font-size: 24px;
        font-weight: 600;
    }
</style>
