<template>
    <v-container id="sense-review">
        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex align-center">
                词义复习
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">到期数量 {{ summary.due_count || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">已复习 {{ reviewedCount }}</v-chip>
                <v-chip class="mx-1" color="foreground">剩余 {{ remainingCount }}</v-chip>
            </div>
        </v-card>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

        <v-card v-if="currentCard" outlined class="rounded-lg pa-5">
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

            <!-- Action buttons -->
            <div class="d-flex justify-end mb-3" style="gap: 8px;">
                <v-btn small text @click="startEdit">
                    <v-icon small left>mdi-pencil</v-icon>编辑
                </v-btn>
                <v-btn small text color="info" @click="viewSource">
                    <v-icon small left>mdi-book-open-page-variant</v-icon>查看原文
                </v-btn>
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

                    <div class="caption text--secondary">FSRS</div>
                    <v-simple-table dense class="no-hover border rounded-lg">
                        <tbody>
                            <tr><td>到期时间</td><td>{{ currentCard.fsrs_due_at }}</td></tr>
                            <tr><td>稳定度</td><td>{{ currentCard.fsrs_stability || '-' }}</td></tr>
                            <tr><td>难度</td><td>{{ currentCard.fsrs_difficulty || '-' }}</td></tr>
                            <tr><td>遗忘次数</td><td>{{ currentCard.fsrs_lapses }}</td></tr>
                        </tbody>
                    </v-simple-table>
                </v-col>
            </v-row>

            <div class="d-flex justify-center flex-wrap mt-6">
                <v-btn depressed rounded color="error" class="ma-2" :disabled="rating" @click="rate('again')">忘了</v-btn>
                <v-btn depressed rounded color="warning" class="ma-2" :disabled="rating" @click="rate('hard')">勉强记得</v-btn>
                <v-btn depressed rounded color="primary" class="ma-2" :disabled="rating" @click="rate('good')">记得</v-btn>
                <v-btn depressed rounded color="success" class="ma-2" :disabled="rating" @click="rate('easy')">很熟</v-btn>
            </div>
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
                // Source context dialog
                sourceDialog: false,
                sourcePayload: {},
                // Snackbar
                snackbar: {
                    show: false,
                    text: '',
                    color: 'success',
                },
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
        mounted() {
            this.loadCards();
        },
        methods: {
            loadCards() {
                this.loading = true;
                this.error = '';
                axios.get('/reviews/senses').then((response) => {
                    this.cards = response.data.cards;
                    this.summary = response.data.summary;
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
                }).catch((error) => {
                    this.error = error.response?.data?.message || '词义卡评分失败。';
                }).finally(() => {
                    this.rating = false;
                });
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
