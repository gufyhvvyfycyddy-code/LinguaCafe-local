<template>
    <v-container fluid class="special-study-page">
        <SpecialStudySession
            v-if="activeSession"
            :session="activeSession"
            @updated="applySession"
            @exit="leaveSession"
        />

        <div v-else class="special-study-setup">
            <div class="d-flex flex-wrap align-center mb-4">
                <div>
                    <div class="text-h5">专项学习</div>
                    <div class="text--secondary mt-1">按目标建立临时学习会话，不移动卡片，也不改变长期归属。</div>
                </div>
                <v-spacer></v-spacer>
                <v-btn text href="/reviews/senses">返回普通复习</v-btn>
            </div>

            <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

            <v-row>
                <v-col cols="12" lg="8">
                    <v-card outlined class="rounded-lg pa-5">
                        <div class="text-h6 mb-4">新建会话</div>
                        <v-select
                            v-model="scenario"
                            :items="scenarioItems"
                            item-text="label"
                            item-value="value"
                            label="学习目标"
                            outlined
                            dense
                        ></v-select>

                        <v-select
                            v-model="executionMode"
                            :items="availableExecutionModes"
                            item-text="label"
                            item-value="value"
                            label="会话行为"
                            outlined
                            dense
                        ></v-select>

                        <v-alert :type="impactAlert.type" dense outlined>
                            {{ impactAlert.text }}
                        </v-alert>

                        <v-row dense>
                            <v-col v-if="showDays" cols="12" sm="6">
                                <v-text-field
                                    v-model.number="days"
                                    type="number"
                                    min="1"
                                    max="365"
                                    label="天数"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="cardLimit"
                                    type="number"
                                    min="1"
                                    max="500"
                                    label="本次最多卡片数"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-select
                                    v-model="sort"
                                    :items="sortItems"
                                    item-text="label"
                                    item-value="value"
                                    label="排序"
                                    outlined
                                    dense
                                ></v-select>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="name"
                                    label="保存名称（可选）"
                                    maxlength="100"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                        </v-row>

                        <v-expansion-panels flat class="mb-4">
                            <v-expansion-panel>
                                <v-expansion-panel-header>进一步筛选（可选）</v-expansion-panel-header>
                                <v-expansion-panel-content>
                                    <v-select
                                        v-model="filters.article_ids"
                                        :items="options.articles"
                                        item-text="name"
                                        item-value="id"
                                        label="文章来源"
                                        multiple
                                        chips
                                        small-chips
                                        outlined
                                        dense
                                    ></v-select>
                                    <v-select
                                        v-model="filters.chapter_ids"
                                        :items="chapterOptions"
                                        item-text="label"
                                        item-value="id"
                                        label="章节"
                                        multiple
                                        chips
                                        small-chips
                                        outlined
                                        dense
                                    ></v-select>
                                    <v-select
                                        v-model="filters.lifecycle_states"
                                        :items="lifecycleItems"
                                        item-text="label"
                                        item-value="value"
                                        label="生命周期"
                                        multiple
                                        chips
                                        small-chips
                                        outlined
                                        dense
                                        :disabled="executionMode !== 'preview'"
                                    ></v-select>
                                    <v-select
                                        v-model="filters.fsrs_states"
                                        :items="fsrsStateItems"
                                        item-text="label"
                                        item-value="value"
                                        label="FSRS 状态"
                                        multiple
                                        chips
                                        small-chips
                                        outlined
                                        dense
                                    ></v-select>
                                </v-expansion-panel-content>
                            </v-expansion-panel>
                        </v-expansion-panels>

                        <v-btn
                            color="primary"
                            :loading="starting"
                            :disabled="starting || !validDefinition"
                            @click="startSession"
                        >
                            建立专项学习会话
                        </v-btn>
                    </v-card>
                </v-col>

                <v-col cols="12" lg="4">
                    <v-card outlined class="rounded-lg pa-4 mb-4">
                        <div class="text-subtitle-1 font-weight-medium mb-2">仅今天的学习上限</div>
                        <div class="caption text--secondary mb-3">临时增加量会在下一个学习日自动失效，不修改永久设置。</div>
                        <v-text-field
                            v-model.number="todayLimits.new_limit_delta"
                            type="number"
                            min="0"
                            max="999"
                            label="额外新卡"
                            outlined
                            dense
                        ></v-text-field>
                        <v-text-field
                            v-model.number="todayLimits.review_limit_delta"
                            type="number"
                            min="0"
                            max="9999"
                            label="额外复习卡"
                            outlined
                            dense
                        ></v-text-field>
                        <v-switch
                            v-model="todayLimits.pause_new_cards"
                            label="今天暂停新卡"
                            class="mt-0"
                        ></v-switch>
                        <v-btn small color="primary" :loading="limitsSaving" @click="saveTodayLimits">应用</v-btn>
                        <v-btn small text :disabled="limitsSaving" @click="clearTodayLimits">清除</v-btn>
                    </v-card>

                    <v-card outlined class="rounded-lg pa-4">
                        <div class="d-flex align-center mb-2">
                            <div class="text-subtitle-1 font-weight-medium">已保存会话</div>
                            <v-spacer></v-spacer>
                            <v-btn icon small :loading="savedLoading" @click="loadSaved">
                                <v-icon small>mdi-refresh</v-icon>
                            </v-btn>
                        </div>
                        <v-progress-linear v-if="savedLoading" indeterminate></v-progress-linear>
                        <v-alert v-else-if="!savedSessions.length" type="info" dense text>
                            还没有保存的会话。
                        </v-alert>
                        <v-list v-else dense>
                            <v-list-item v-for="item in savedSessions" :key="item.id">
                                <v-list-item-content>
                                    <v-list-item-title>{{ item.name }}</v-list-item-title>
                                    <v-list-item-subtitle>
                                        {{ scenarioLabel(item.scenario) }} · {{ statusLabel(item.status) }}
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action>
                                    <v-btn small text color="primary" @click="openSaved(item)">打开</v-btn>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </v-card>
                </v-col>
            </v-row>
        </div>
    </v-container>
</template>

<script>
    import SpecialStudySession from './SpecialStudySession.vue';

    export default {
        components: { SpecialStudySession },
        data() {
            return {
                scenario: 'today_forgotten',
                executionMode: 'preview',
                sort: 'lowest_retrievability',
                days: 7,
                cardLimit: 100,
                name: '',
                filters: {
                    tag_ids: [],
                    markers: [],
                    article_ids: [],
                    chapter_ids: [],
                    lifecycle_states: ['active'],
                    fsrs_states: [],
                },
                options: { tags: [], markers: [], articles: [], chapters: [] },
                activeSession: null,
                savedSessions: [],
                savedLoading: false,
                starting: false,
                error: '',
                limitsSaving: false,
                todayLimits: {
                    new_limit_delta: 0,
                    review_limit_delta: 0,
                    pause_new_cards: false,
                },
                scenarioItems: [
                    { value: 'today_forgotten', label: '今天回答 Again 的词义' },
                    { value: 'backlog', label: '逾期与积压' },
                    { value: 'review_ahead', label: '提前复习未来卡片' },
                    { value: 'recent_new', label: '预览最近新建词义' },
                    { value: 'filtered', label: '按筛选条件学习' },
                ],
                sortItems: [
                    { value: 'most_overdue', label: '最逾期优先' },
                    { value: 'most_lapses', label: 'Lapses 最多优先' },
                    { value: 'lowest_retrievability', label: '可提取率最低优先' },
                    { value: 'random', label: '随机（本次固定）' },
                    { value: 'source', label: '按文章来源' },
                ],
                lifecycleItems: [
                    { value: 'active', label: 'Active' },
                    { value: 'buried', label: 'Buried' },
                    { value: 'suspended', label: 'Suspended' },
                    { value: 'archived', label: 'Archived' },
                ],
                fsrsStateItems: [
                    { value: 'new', label: 'New' },
                    { value: 'learning', label: 'Learning' },
                    { value: 'review', label: 'Review' },
                    { value: 'relearning', label: 'Relearning' },
                ],
            };
        },
        computed: {
            availableExecutionModes() {
                if (this.scenario === 'recent_new') {
                    return [{ value: 'preview', label: '预览（不影响排程）' }];
                }
                if (this.scenario === 'review_ahead') {
                    return [
                        { value: 'preview', label: '预览（不影响排程）' },
                        { value: 'early_review', label: '提前正式复习（会重新排程）' },
                    ];
                }
                return [
                    { value: 'preview', label: '预览（不影响排程）' },
                    { value: 'formal', label: '正式评分（写入 ReviewLog 与 FSRS）' },
                ];
            },
            impactAlert() {
                if (this.executionMode === 'preview') {
                    return { type: 'info', text: '预览模式：按钮只推进本次会话，不写 ReviewLog，也不改变 FSRS 或正常队列。' };
                }
                if (this.executionMode === 'early_review') {
                    return { type: 'warning', text: '提前正式复习：每次评分都会写入 ReviewLog，并按当前时刻重新计算 FSRS 到期时间。' };
                }
                return { type: 'warning', text: '正式评分：每次回答都会进入正常 ReviewLog、FSRS 和撤销账本，并计入今天的复习数量。' };
            },
            showDays() {
                return ['review_ahead', 'recent_new'].includes(this.scenario);
            },
            validDefinition() {
                return Number.isInteger(Number(this.cardLimit))
                    && Number(this.cardLimit) >= 1
                    && Number(this.cardLimit) <= 500
                    && (!this.showDays || (
                        Number.isInteger(Number(this.days))
                        && Number(this.days) >= 1
                        && Number(this.days) <= 365
                    ));
            },
            chapterOptions() {
                return this.options.chapters.map(item => ({
                    id: item.id,
                    label: `${item.article_name || '未命名材料'} · ${item.name || `章节 ${item.id}`}`,
                }));
            },
        },
        watch: {
            scenario() {
                const allowed = this.availableExecutionModes.map(item => item.value);
                if (!allowed.includes(this.executionMode)) {
                    this.executionMode = allowed[0];
                }
            },
            executionMode(value) {
                if (value !== 'preview') {
                    this.filters.lifecycle_states = ['active'];
                }
            },
        },
        mounted() {
            this.loadOptions();
            this.loadSaved();
            this.loadTodayLimits();
        },
        methods: {
            async loadOptions() {
                try {
                    const response = await axios.get('/special-study/options');
                    this.options = Object.assign(this.options, response.data || {});
                } catch (error) {
                    this.error = '无法加载专项学习筛选项。';
                }
            },
            async loadSaved() {
                this.savedLoading = true;
                try {
                    const response = await axios.get('/special-study/sessions');
                    this.savedSessions = response.data.sessions || [];
                } catch (error) {
                    this.error = '无法加载已保存会话。';
                } finally {
                    this.savedLoading = false;
                }
            },
            async loadTodayLimits() {
                try {
                    const response = await axios.get('/reviews/senses/today-limits');
                    const override = response.data.override || {};
                    this.todayLimits = {
                        new_limit_delta: Number(override.new_limit_delta || 0),
                        review_limit_delta: Number(override.review_limit_delta || 0),
                        pause_new_cards: Boolean(override.pause_new_cards),
                    };
                } catch (error) {
                    this.error = '无法加载今天的学习上限。';
                }
            },
            async saveTodayLimits() {
                this.limitsSaving = true;
                try {
                    await axios.put('/reviews/senses/today-limits', this.todayLimits);
                } catch (error) {
                    this.applyError(error, '无法保存今天的学习上限。');
                } finally {
                    this.limitsSaving = false;
                }
            },
            async clearTodayLimits() {
                this.limitsSaving = true;
                try {
                    await axios.delete('/reviews/senses/today-limits');
                    this.todayLimits = { new_limit_delta: 0, review_limit_delta: 0, pause_new_cards: false };
                } catch (error) {
                    this.applyError(error, '无法清除今天的学习上限。');
                } finally {
                    this.limitsSaving = false;
                }
            },
            async startSession() {
                if (!this.validDefinition) return;
                this.starting = true;
                this.error = '';
                try {
                    const response = await axios.post('/special-study/sessions', {
                        scenario: this.scenario,
                        execution_mode: this.executionMode,
                        sort: this.sort,
                        days: Number(this.days),
                        card_limit: Number(this.cardLimit),
                        name: this.name.trim() || null,
                        filters: this.filters,
                    });
                    this.activeSession = response.data;
                    if (response.data.saved) await this.loadSaved();
                } catch (error) {
                    this.applyError(error, '无法建立专项学习会话。');
                } finally {
                    this.starting = false;
                }
            },
            async openSaved(item) {
                this.error = '';
                try {
                    const response = await axios.get(`/special-study/sessions/${item.id}`);
                    this.activeSession = response.data;
                } catch (error) {
                    this.applyError(error, '无法打开已保存会话。');
                }
            },
            applySession(session) {
                this.activeSession = session;
                if (session && session.saved) this.loadSaved();
            },
            leaveSession() {
                this.activeSession = null;
                this.loadSaved();
            },
            applyError(error, fallback) {
                const payload = error.response && error.response.data;
                this.error = payload && payload.message ? payload.message : fallback;
            },
            scenarioLabel(value) {
                const item = this.scenarioItems.find(candidate => candidate.value === value);
                return item ? item.label : value;
            },
            statusLabel(value) {
                return { active: '进行中', completed: '已完成', ended: '已结束' }[value] || value;
            },
        },
    };
</script>

<style scoped>
    .special-study-setup {
        max-width: 1180px;
        margin: 0 auto;
    }
</style>
