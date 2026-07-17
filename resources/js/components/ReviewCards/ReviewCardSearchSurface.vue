<template>
    <div class="review-card-search-surface">
        <review-card-saved-search-panel
            :filter-state="currentFilterState"
            :language="language"
            :initial-saved-search-id="initialSavedSearchId"
            @apply="applySavedSearch"
        />

        <v-row class="mb-3" dense align="center">
            <v-col cols="12" sm="5" md="4">
                <v-text-field
                    v-model="searchQuery"
                    label="搜索词义，或输入高级语法"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    dense
                    hide-details
                    @keyup.enter="search"
                    @click:clear="search"
                />
                <div class="d-flex align-center mt-1" style="gap: 4px;">
                    <v-btn x-small text color="info" @click="searchHelpDialog = true">
                        <v-icon x-small left>mdi-help-circle-outline</v-icon>高级搜索语法
                    </v-btn>
                </div>
                <div
                    v-if="searchMeta && searchMeta.tokens && searchMeta.tokens.length > 0"
                    class="d-flex flex-wrap align-center mt-1"
                    style="gap: 4px;"
                >
                    <v-chip
                        v-for="token in searchMeta.tokens"
                        :key="token"
                        x-small
                        close
                        color="primary"
                        text-color="white"
                        @click:close="removeToken(token)"
                    >{{ token }}</v-chip>
                </div>
                <v-alert
                    v-if="searchErrors.length > 0"
                    type="error"
                    dense
                    text
                    class="mt-2 mb-0"
                >
                    <div class="text-caption">高级搜索语法有误：</div>
                    <div v-for="(err, idx) in searchErrors" :key="idx" class="text-caption">
                        <strong>{{ err.token }}</strong> — {{ err.reason }}
                        <span v-if="err.example" class="text--secondary">（示例：{{ err.example }}）</span>
                    </div>
                </v-alert>
            </v-col>

            <v-col cols="12" sm="7" md="8">
                <v-btn-toggle v-model="activeFilter" mandatory dense class="flex-wrap">
                    <v-btn small value="all" @click="applyFilter('all')">全部</v-btn>
                    <v-btn small value="due" @click="applyFilter('due')">到期</v-btn>
                    <v-btn small value="future" @click="applyFilter('future')">未来到期</v-btn>
                    <v-btn small value="active" @click="applyFilter('active')">学习中</v-btn>
                    <v-btn small value="buried" @click="applyFilter('buried')">已埋藏</v-btn>
                    <v-btn small value="suspended" @click="applyFilter('suspended')">已暂停</v-btn>
                    <v-btn small value="archived" @click="applyFilter('archived')">已归档</v-btn>
                    <v-btn small value="missing_definition" @click="applyFilter('missing_definition')">缺释义</v-btn>
                    <v-btn small value="missing_example" @click="applyFilter('missing_example')">缺例句</v-btn>
                    <v-btn small value="missing_source" @click="applyFilter('missing_source')">缺溯源</v-btn>
                    <v-btn small value="leech" class="error--text" @click="applyFilter('leech')">高遗忘</v-btn>
                    <v-btn small value="struggling" class="warning--text" @click="applyFilter('struggling')">需关注</v-btn>
                </v-btn-toggle>
            </v-col>
        </v-row>

        <v-expansion-panels v-model="advancedPanelOpen" flat class="mb-3">
            <v-expansion-panel>
                <v-expansion-panel-header class="font-weight-medium">
                    高级筛选
                    <v-chip v-if="hasAdvancedFilter" x-small color="primary" class="ml-2" label>已启用</v-chip>
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <v-row dense>
                        <v-col cols="12" sm="6" md="3">
                            <div class="text-caption text--secondary mb-1">FSRS 状态</div>
                            <div class="d-flex flex-wrap" style="gap: 4px;">
                                <v-chip
                                    v-for="state in fsrsStateOptions"
                                    :key="state.value"
                                    small
                                    :color="advancedFilters.fsrsStates.includes(state.value) ? 'primary' : ''"
                                    :outlined="!advancedFilters.fsrsStates.includes(state.value)"
                                    style="cursor: pointer;"
                                    @click="toggleFsrsState(state.value)"
                                >{{ state.label }}</v-chip>
                            </div>
                        </v-col>
                        <v-col cols="12" sm="6" md="3">
                            <v-select
                                v-model="advancedFilters.dueRange"
                                :items="dueRangeOptions"
                                label="到期范围"
                                dense
                                hide-details
                            />
                        </v-col>
                        <v-col cols="6" sm="3" md="2">
                            <v-text-field
                                v-model="advancedFilters.repsMin"
                                label="最少复习次数"
                                type="number"
                                min="0"
                                dense
                                hide-details
                            />
                        </v-col>
                        <v-col cols="6" sm="3" md="2">
                            <v-text-field
                                v-model="advancedFilters.lapsesMin"
                                label="最少遗忘次数"
                                type="number"
                                min="0"
                                dense
                                hide-details
                            />
                        </v-col>
                        <v-col cols="12" sm="6" md="2" class="d-flex flex-wrap align-end" style="gap: 8px;">
                            <v-btn small color="primary" @click="applyAdvancedFilter">应用筛选</v-btn>
                            <v-btn small text @click="clearAdvancedFilter">清空高级筛选</v-btn>
                        </v-col>
                    </v-row>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <v-dialog v-model="searchHelpDialog" max-width="560">
            <v-card>
                <v-card-title>高级搜索语法</v-card-title>
                <v-card-text>
                    <p class="text-body-2 mb-3">
                        在搜索框中输入以下 token 可以精确筛选复习卡。所有条件使用 AND 语义组合。普通文本仍会搜索 Lemma / 释义 / 例句。
                    </p>
                    <v-list dense class="mb-2">
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>is:leech</code></v-list-item-title>
                                <v-list-item-subtitle>只显示高遗忘（Leech）的卡片</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>is:struggling</code></v-list-item-title>
                                <v-list-item-subtitle>只显示需关注的卡片</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>is:active</code> / <code>is:buried</code> / <code>is:suspended</code> / <code>is:archived</code></v-list-item-title>
                                <v-list-item-subtitle>按生命周期状态筛选（最多一个）</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>rated:again</code> / <code>rated:hard</code> / <code>rated:good</code> / <code>rated:easy</code></v-list-item-title>
                                <v-list-item-subtitle>只显示有对应正式评分记录的卡片；多个评分使用 AND 组合</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>prop:lapses&gt;=2</code></v-list-item-title>
                                <v-list-item-subtitle>按遗忘次数筛选，支持 =, &gt;, &gt;=, &lt;, &lt;=</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>flag:1</code> … <code>flag:7</code></v-list-item-title>
                                <v-list-item-subtitle>按卡片标记筛选；<code>flag:0</code> 表示未标记</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                        <v-list-item>
                            <v-list-item-content>
                                <v-list-item-title><code>state:new</code> / <code>state:learning</code> / <code>state:review</code> / <code>state:relearning</code></v-list-item-title>
                                <v-list-item-subtitle>按 FSRS 复习状态筛选（最多一个）</v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                    </v-list>
                    <v-divider class="my-2" />
                    <p class="text-body-2 mb-1"><strong>组合示例：</strong></p>
                    <p class="text-body-2 mb-1"><code>charge is:leech</code> — 搜索 charge 且是 Leech</p>
                    <p class="text-body-2 mb-1"><code>is:leech is:suspended</code> — Leech 且已暂停</p>
                    <p class="text-body-2 mb-1"><code>rated:again prop:lapses&gt;=2</code> — 有 Again 记录且遗忘 ≥ 2</p>
                    <p class="text-body-2 mb-1"><code>rated:good rated:easy</code> — 同时有 Good 和 Easy 正式评分记录</p>
                    <p class="text-body-2 mb-0"><code>charge rated:again prop:lapses&gt;=2</code> — 全部组合</p>
                    <p class="text-body-2 mb-0"><code>state:review</code> — 只显示复习状态的卡片</p>
                    <p class="text-body-2 mb-0"><code>charge state:new rated:again</code> — 搜索 charge、新卡片且有 Again 记录</p>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="searchHelpDialog = false">关闭</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
import ReviewCardSavedSearchPanel from './ReviewCardSavedSearchPanel.vue';
import {
    applyReviewCardManageFilterState,
    buildReviewCardManageFilterState,
} from '../../services/ReviewCardManageFilterState.js';

export default {
    components: { ReviewCardSavedSearchPanel },
    props: {
        filterState: { type: Object, required: true },
        language: { type: String, default: 'english' },
        initialSavedSearchId: { type: Number, default: null },
        searchMeta: { type: Object, default: null },
        searchErrors: { type: Array, default: () => [] },
    },
    data() {
        return {
            searchQuery: '',
            activeFilter: 'active',
            currentFilter: 'active',
            sortBy: 'id',
            sortDir: 'desc',
            searchHelpDialog: false,
            advancedPanelOpen: undefined,
            advancedFilters: {
                fsrsStates: [],
                dueRange: 'all',
                repsMin: null,
                lapsesMin: null,
            },
            fsrsStateOptions: [
                { label: '新卡', value: 'new' },
                { label: '学习中', value: 'learning' },
                { label: '复习中', value: 'review' },
                { label: '重新学习', value: 'relearning' },
            ],
            dueRangeOptions: [
                { text: '全部', value: 'all' },
                { text: '已逾期', value: 'overdue' },
                { text: '今天', value: 'today' },
                { text: '未来 7 天', value: 'next7' },
                { text: '未来', value: 'future' },
                { text: '无到期', value: 'none' },
            ],
        };
    },
    computed: {
        currentFilterState() {
            return buildReviewCardManageFilterState(this);
        },
        hasAdvancedFilter() {
            return this.advancedFilters.fsrsStates.length > 0
                || this.advancedFilters.dueRange !== 'all'
                || this.advancedFilters.repsMin !== null
                || this.advancedFilters.lapsesMin !== null;
        },
    },
    watch: {
        filterState: {
            immediate: true,
            deep: true,
            handler(state) {
                applyReviewCardManageFilterState(this, state || {});
            },
        },
    },
    methods: {
        emitApply() {
            if (this.detectAdvancedTokens(this.searchQuery)) {
                this.currentFilter = 'all';
                this.activeFilter = 'all';
            }
            this.$emit('apply', this.currentFilterState);
        },
        search() {
            this.emitApply();
        },
        applySavedSearch(savedSearch) {
            applyReviewCardManageFilterState(this, savedSearch.filter_state || {});
            this.emitApply();
        },
        detectAdvancedTokens(query) {
            if (!query) return false;
            return query.trim().split(/\s+/).some((segment) => {
                const colon = segment.indexOf(':');
                if (colon <= 0) return false;
                return ['is', 'rated', 'prop'].includes(segment.substring(0, colon).toLowerCase());
            });
        },
        stripIsTokens(query) {
            if (!query) return '';
            return query.trim().split(/\s+/).filter((segment) => {
                const colon = segment.indexOf(':');
                return colon <= 0 || segment.substring(0, colon).toLowerCase() !== 'is';
            }).join(' ').trim();
        },
        removeToken(token) {
            if (!this.searchQuery || !token) return;
            const target = token.toLowerCase();
            let removed = false;
            this.searchQuery = this.searchQuery.trim().split(/\s+/).filter((segment) => {
                if (!removed && segment.toLowerCase() === target) {
                    removed = true;
                    return false;
                }
                return true;
            }).join(' ').trim();
            this.emitApply();
        },
        applyFilter(filter) {
            this.activeFilter = filter;
            this.currentFilter = filter;
            this.searchQuery = this.stripIsTokens(this.searchQuery);
            this.emitApply();
        },
        toggleFsrsState(value) {
            const index = this.advancedFilters.fsrsStates.indexOf(value);
            if (index >= 0) this.advancedFilters.fsrsStates.splice(index, 1);
            else this.advancedFilters.fsrsStates.push(value);
        },
        applyAdvancedFilter() {
            this.emitApply();
        },
        clearAdvancedFilter() {
            this.advancedFilters = {
                fsrsStates: [],
                dueRange: 'all',
                repsMin: null,
                lapsesMin: null,
            };
            this.emitApply();
        },
    },
};
</script>
