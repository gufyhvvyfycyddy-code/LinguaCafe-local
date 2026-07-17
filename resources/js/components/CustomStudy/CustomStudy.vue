<template>
    <v-container class="custom-study-page" fluid>
        <CustomStudySession
            v-if="activeToken"
            :initial-token="activeToken"
            :initial-payload="initialSessionPayload"
            @token-updated="updateToken"
            @exit="clearSession"
            @expired="onSessionExpired"
        />

        <v-card v-else outlined class="rounded-lg pa-5 custom-study-setup">
            <div class="d-flex align-center mb-2">
                <div>
                    <div class="text-h5">自定义学习</div>
                    <div class="text--secondary mt-1">选择一个只读预览队列，按自己的节奏练习词义。</div>
                </div>
            </div>

            <v-alert type="info" dense outlined class="mb-5">
                这是预览学习：不会写入复习记录，也不会改变正常复习队列或 FSRS 排程。
            </v-alert>

            <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

            <v-form @submit.prevent="startSession">
                <v-radio-group v-model="mode" label="学习范围" class="mt-0">
                    <v-radio value="today_forgotten" label="今天遗忘过的词义"></v-radio>
                    <v-radio value="marked" label="已标记的词义卡"></v-radio>
                    <v-radio value="overdue" label="已逾期的词义"></v-radio>
                    <v-radio value="source_chapter" label="按原文篇章"></v-radio>
                    <v-radio value="leech_attention" label="需要特别关注的困难词义"></v-radio>
                </v-radio-group>

                <v-select
                    v-if="mode === 'source_chapter'"
                    v-model="chapterId"
                    :items="chapterOptions"
                    :loading="chapterOptionsLoading"
                    :disabled="chapterOptionsLoading"
                    item-text="label"
                    item-value="id"
                    label="篇章"
                    outlined
                    dense
                    :error-messages="chapterError"
                    @change="chapterError = ''"
                ></v-select>

                <v-alert
                    v-if="mode === 'source_chapter' && !chapterOptionsLoading && !chapterOptions.length"
                    type="info"
                    dense
                    outlined
                >
                    当前没有包含可用预览卡片的篇章。
                </v-alert>

                <v-alert v-if="selectedChapterOption" type="info" dense outlined>
                    当前可用 {{ selectedChapterOption.candidateCount }} 张，本次最多学习 {{ validCardLimit || 100 }} 张。
                </v-alert>

                <v-radio-group v-if="mode === 'leech_attention'" v-model="leechSubMode" label="困难词义范围">
                    <v-radio value="leech_only" label="仅顽固遗忘词义"></v-radio>
                    <v-radio value="leech_plus_struggling" label="顽固遗忘词义与近期困难词义"></v-radio>
                </v-radio-group>

                <v-text-field
                    v-model.number="cardLimit"
                    type="number"
                    min="1"
                    max="500"
                    step="1"
                    label="本次最多练习卡片数"
                    hint="1–500，默认 100"
                    persistent-hint
                    outlined
                    dense
                    class="custom-study-card-limit"
                    :error-messages="cardLimitError"
                    @input="cardLimitError = ''"
                    @blur="validateCardLimit"
                ></v-text-field>

                <v-btn color="primary" :loading="starting" :disabled="!canStart || starting" type="submit">
                    开始预览学习
                </v-btn>
            </v-form>
        </v-card>
    </v-container>
</template>

<script>
    import CustomStudySession from './CustomStudySession.vue';

    const SESSION_TOKEN_KEY = 'linguacafe.custom-study.preview-token';

    export default {
        components: {
            CustomStudySession,
        },
        data() {
            return {
                mode: 'today_forgotten',
                chapterId: null,
                leechSubMode: 'leech_only',
                cardLimit: 100,
                chapterOptions: [],
                chapterOptionsLoading: false,
                starting: false,
                error: '',
                chapterError: '',
                cardLimitError: '',
                activeToken: '',
                initialSessionPayload: null,
            };
        },
        watch: {
            mode(value) {
                this.error = '';
                this.chapterError = '';
                if (value === 'source_chapter') {
                    this.loadChapterOptions();
                }
            },
        },
        computed: {
            validCardLimit() {
                const cardLimit = Number(this.cardLimit);
                return Number.isInteger(cardLimit) && cardLimit >= 1 && cardLimit <= 500
                    ? cardLimit
                    : null;
            },
            selectedChapterOption() {
                if (!Number.isInteger(this.chapterId)) {
                    return null;
                }
                return this.chapterOptions.find((item) => item.id === this.chapterId) || null;
            },
            canStart() {
                if (!this.validCardLimit) {
                    return false;
                }
                return this.mode !== 'source_chapter' || Boolean(this.selectedChapterOption);
            },
        },
        mounted() {
            if (this.$route?.query?.mode === 'marked') this.mode = 'marked';
            this.activeToken = window.sessionStorage.getItem(SESSION_TOKEN_KEY) || '';
        },
        methods: {
            loadChapterOptions() {
                if (this.chapterOptionsLoading || this.chapterOptions.length) {
                    return;
                }

                this.chapterOptionsLoading = true;
                axios.get('/custom-study/chapter-options')
                    .then((response) => {
                        const items = response.data && Array.isArray(response.data.items) ? response.data.items : [];
                        this.chapterOptions = items.map((item) => ({
                            id: item.chapter_id,
                            candidateCount: item.candidate_count,
                            label: this.chapterLabel(item),
                        }));
                    })
                    .catch(() => {
                        this.error = '无法加载可用篇章，请稍后重试。';
                    })
                    .finally(() => {
                        this.chapterOptionsLoading = false;
                    });
            },
            chapterLabel(item) {
                const bookTitle = item.book_name || '未命名材料';
                const chapterTitle = item.chapter_name || `篇章 ${item.chapter_id}`;
                const count = Number.isInteger(item.candidate_count) ? ` · ${item.candidate_count} 张可用卡片` : '';
                return `${bookTitle} · ${chapterTitle}${count}`;
            },
            validateCardLimit() {
                this.cardLimitError = this.validCardLimit
                    ? ''
                    : '请输入 1 到 500 之间的整数。';
                return Boolean(this.validCardLimit);
            },
            startSession() {
                this.error = '';
                this.chapterError = '';

                if (!this.validateCardLimit()) {
                    return;
                }
                const cardLimit = this.validCardLimit;

                const parameters = {};
                if (this.mode === 'source_chapter') {
                    if (!Number.isInteger(this.chapterId) || this.chapterId <= 0) {
                        this.chapterError = '请选择一个篇章。';
                        return;
                    }
                    parameters.chapter_id = this.chapterId;
                }
                if (this.mode === 'leech_attention') {
                    parameters.sub_mode = this.leechSubMode;
                }

                this.starting = true;
                axios.post('/custom-study/sessions', {
                    mode: this.mode,
                    parameters: parameters,
                    card_limit: cardLimit,
                })
                    .then((response) => {
                        const payload = response.data || {};
                        if (!payload.token) {
                            this.error = '创建预览会话失败，请重试。';
                            return;
                        }
                        this.initialSessionPayload = payload;
                        this.updateToken(payload.token);
                    })
                    .catch((requestError) => {
                        this.applyRequestError(requestError);
                    })
                    .finally(() => {
                        this.starting = false;
                    });
            },
            updateToken(token) {
                if (!token) {
                    return;
                }
                this.activeToken = token;
                window.sessionStorage.setItem(SESSION_TOKEN_KEY, token);
            },
            onSessionExpired(message) {
                this.clearSession();
                this.error = message || '预览会话已过期或不存在。';
            },
            clearSession() {
                this.activeToken = '';
                this.initialSessionPayload = null;
                window.sessionStorage.removeItem(SESSION_TOKEN_KEY);
            },
            applyRequestError(requestError) {
                const payload = requestError.response && requestError.response.data ? requestError.response.data : null;
                this.error = payload && payload.message ? payload.message : '请求失败，请稍后重试。';
                if (payload && payload.error && payload.error.field === 'chapter_id') {
                    this.chapterError = this.error;
                }
                if (payload && payload.error && payload.error.field === 'card_limit') {
                    this.cardLimitError = this.error;
                }
            },
        },
    };
</script>

<style scoped>
    .custom-study-setup {
        max-width: 760px;
        margin: 0 auto;
    }

    .custom-study-card-limit {
        max-width: 320px;
    }
</style>
