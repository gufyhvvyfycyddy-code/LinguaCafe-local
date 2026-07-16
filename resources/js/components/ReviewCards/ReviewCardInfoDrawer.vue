<template>
    <v-navigation-drawer
        v-model="drawerOpen"
        right
        temporary
        fixed
        width="420"
        class="detail-drawer"
    >
        <div v-if="detailLoading && !detailTarget" class="d-flex align-center justify-center" style="min-height: 240px;">
            <div class="text-center">
                <v-progress-circular indeterminate color="primary" size="32" />
                <div class="text-caption text--secondary mt-3">加载卡片详情中…</div>
            </div>
        </div>
        <div v-else-if="detailError && !detailTarget" class="d-flex align-center justify-center pa-4" style="min-height: 240px;">
            <div class="text-center">
                <v-icon color="error" large>mdi-alert-circle-outline</v-icon>
                <div class="error--text mt-2 text-body-2">{{ detailError }}</div>
                <v-btn text color="primary" class="mt-2" @click="closeDetail">关闭</v-btn>
            </div>
        </div>
        <template v-else-if="detailTarget">
            <v-card flat>
                <v-card-title class="d-flex align-center">
                    <span>复习卡详情</span>
                    <v-spacer />
                    <v-btn icon small @click="closeDetail"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-alert
                    v-if="deepLinkSource"
                    type="info"
                    dense
                    text
                    class="mx-4 mb-0"
                    icon="mdi-information-outline"
                >从学习报告打开。</v-alert>
                <v-alert v-if="detailLoading" type="info" dense text class="mx-4 mb-0" icon="mdi-loading mdi-spin">
                    正在加载最新详情…
                </v-alert>
                <v-alert v-if="detailError" type="error" dense text class="mx-4 mb-0" icon="mdi-alert-circle-outline">
                    {{ detailError }}
                </v-alert>
                <v-card-subtitle class="pb-0">
                    {{ detailTarget.lemma }} / {{ detailTarget.surface_form }} / {{ detailTarget.pos }}
                </v-card-subtitle>
                <v-card-text class="detail-content">
                    <v-tabs v-model="detailTab" grow class="mb-2">
                        <v-tab href="#overview">概览</v-tab>
                        <v-tab href="#history">历史</v-tab>
                        <v-tab href="#diagnosis">诊断</v-tab>
                    </v-tabs>
                    <v-tabs-items v-model="detailTab">
                        <v-tab-item value="overview">
                            <div class="detail-section">
                                <div class="detail-section-title">基本信息</div>
                                <detail-row label="ReviewCard ID" :value="detailTarget.review_card_id" />
                                <detail-row label="WordSense ID" :value="detailTarget.word_sense_id" />
                                <detail-row label="Lemma" :value="detailTarget.lemma" />
                                <detail-row label="Surface" :value="displayValue(detailTarget.surface_form)" />
                                <detail-row label="POS" :value="displayValue(detailTarget.pos)" />
                                <div class="detail-row">
                                    <span class="detail-label">状态</span>
                                    <span class="detail-value"><v-chip x-small :color="stateColor(detailTarget.lifecycle_state)">{{ stateLabel(detailTarget.lifecycle_state) }}</v-chip></span>
                                </div>
                                <detail-row label="FSRS State" :value="displayValue(detailTarget.fsrs_state)" />
                            </div>
                            <v-divider class="my-3" />
                            <div class="detail-section">
                                <div class="detail-section-title">释义信息</div>
                                <detail-row label="中文释义" :value="displayValue(detailTarget.sense_zh)" />
                                <detail-row label="英文释义" :value="displayValue(detailTarget.sense_en)" />
                                <detail-row label="英文例句" :value="displayValue(detailTarget.example_sentence_en)" />
                                <detail-row label="中文例句" :value="displayValue(detailTarget.example_sentence_zh)" />
                                <div class="detail-row">
                                    <span class="detail-label">近义译法</span>
                                    <span class="detail-value">
                                        <template v-if="detailTarget.aliases_zh && detailTarget.aliases_zh.length">
                                            <v-chip v-for="(alias, index) in detailTarget.aliases_zh" :key="index" x-small class="mr-1">{{ alias }}</v-chip>
                                        </template><template v-else>—</template>
                                    </span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">搭配</span>
                                    <span class="detail-value">
                                        <template v-if="detailTarget.collocations && detailTarget.collocations.length">
                                            <v-chip v-for="(collocation, index) in detailTarget.collocations" :key="index" x-small class="mr-1">{{ collocation }}</v-chip>
                                        </template><template v-else>—</template>
                                    </span>
                                </div>
                            </div>
                            <v-divider class="my-3" />
                            <div class="detail-section">
                                <div class="detail-section-title">溯源信息</div>
                                <detail-row label="来源" :value="detailTarget.source_display_label || detailTarget.source_chapter_title || sourceKindLabel(detailTarget.source_kind)" :value-class="detailSourceClass(detailTarget)" />
                                <detail-row label="来源类型" :value="sourceKindLabel(detailTarget.source_kind)" />
                                <detail-row label="来源章节 ID" :value="displayValue(detailTarget.source_chapter_id)" />
                            </div>
                            <v-divider class="my-3" />
                            <div class="detail-section">
                                <div class="detail-section-title">FSRS 信息</div>
                                <detail-row label="到期时间" :value="formatDateTime(detailTarget.fsrs_due_at)" />
                                <detail-row label="最近复习" :value="formatDateTime(detailTarget.fsrs_last_reviewed_at)" />
                                <detail-row label="稳定度" :value="formatFsrsNumber(detailTarget.fsrs_stability)" />
                                <detail-row label="难度" :value="formatFsrsNumber(detailTarget.fsrs_difficulty)" />
                                <detail-row label="复习次数" :value="displayValue(detailTarget.fsrs_reps, 0)" />
                                <detail-row label="遗忘次数" :value="displayValue(detailTarget.fsrs_lapses, 0)" />
                            </div>
                            <v-divider class="my-3" />
                            <div class="detail-section">
                                <div class="detail-section-title">生命周期</div>
                                <div class="detail-row">
                                    <span class="detail-label">当前状态</span>
                                    <span class="detail-value"><v-chip x-small :color="stateColor(detailTarget.lifecycle_state)">{{ stateLabel(detailTarget.lifecycle_state) }}</v-chip></span>
                                </div>
                                <detail-row label="埋藏到期" :value="formatDateTime(detailTarget.buried_until)" />
                                <detail-row label="状态变更时间" :value="formatDateTime(detailTarget.lifecycle_changed_at)" />
                            </div>
                            <v-divider class="my-3" />
                            <div class="detail-section">
                                <div class="detail-section-title">缺失状态</div>
                                <detail-row label="缺释义" :value="detailTarget.missing_definition ? '是' : '否'" />
                                <detail-row label="缺例句" :value="detailTarget.missing_example ? '是' : '否'" />
                                <detail-row label="溯源状态" :value="sourceStatusLabel(detailTarget)" :value-class="detailSourceClass(detailTarget)" />
                            </div>
                        </v-tab-item>

                        <v-tab-item value="history">
                            <div class="detail-section">
                                <div class="detail-section-title">生命周期记录（最近 {{ lifecycleEventsLimit }} 条）</div>
                                <div v-if="lifecycleEvents.length === 0" class="text-caption text--secondary py-2">暂无生命周期记录。</div>
                                <div v-for="event in lifecycleEvents" v-else :key="event.id" class="log-entry mb-2 pa-2">
                                    <div class="d-flex align-center" style="gap: 6px;">
                                        <v-chip x-small :color="actionColor(event.action)">{{ actionLabel(event.action) }}</v-chip>
                                        <span class="text-caption">| {{ event.source }}</span><v-spacer />
                                        <span class="text-caption text--secondary">{{ formatDateTime(event.created_at) }}</span>
                                    </div>
                                    <div class="text-caption mt-1">{{ stateLabel(event.previous_state) }} → {{ stateLabel(event.new_state) }}</div>
                                </div>
                            </div>
                            <v-divider class="my-3" />
                            <div class="detail-section">
                                <div class="detail-section-title">最近复习记录（最近 {{ reviewLogsLimit }} 条）</div>
                                <div v-if="reviewLogs.length === 0" class="text-caption text--secondary py-2">暂无复习记录。</div>
                                <div v-for="log in reviewLogs" v-else :key="log.id" class="log-entry mb-2 pa-2">
                                    <div class="d-flex align-center" style="gap: 6px;">
                                        <v-chip x-small :color="logRatingColor(log.rating)">{{ log.rating }}</v-chip>
                                        <span class="text-caption">| {{ log.source }}</span><v-spacer />
                                        <span class="text-caption text--secondary">{{ formatDateTime(log.reviewed_at) }}</span>
                                    </div>
                                    <div class="text-caption mt-1">{{ log.previous_state || '—' }} → {{ log.new_state || '—' }}</div>
                                    <div class="text-caption text--secondary mt-1">S: {{ formatFsrsNumber(log.previous_stability) }} → {{ formatFsrsNumber(log.new_stability) }} | D: {{ formatFsrsNumber(log.previous_difficulty) }} → {{ formatFsrsNumber(log.new_difficulty) }}</div>
                                    <div class="text-caption text--secondary">到期: {{ formatDateTime(log.previous_due_at) }} → {{ formatDateTime(log.new_due_at) }}</div>
                                    <div v-if="log.undone" class="text-caption mt-1 d-flex align-center" style="gap: 4px;">
                                        <v-chip x-small color="grey">已撤销</v-chip>
                                        <span class="text--secondary">撤销时间: {{ formatDateTime(log.undone_at) }}<span v-if="log.undo_source"> · 来源: {{ log.undo_source }}</span></span>
                                    </div>
                                </div>
                            </div>
                        </v-tab-item>

                        <v-tab-item value="diagnosis">
                            <div class="detail-section">
                                <div class="detail-section-title">遗忘诊断</div>
                                <template v-if="leech">
                                    <div class="detail-row"><span class="detail-label">遗忘状态</span><span class="detail-value">
                                        <v-chip x-small :color="leechStatusColor(leech.status)" text-color="white">{{ leechStatusLabel(leech.status) }}</v-chip>
                                        <v-chip v-if="leech.status !== 'stable'" x-small :color="leechSeverityColor(leech.severity)" outlined class="ml-1">严重度：{{ leechSeverityText(leech.severity) }}</v-chip>
                                    </span></div>
                                    <div v-if="leech.reasons && leech.reasons.length" class="detail-row"><span class="detail-label">原因</span><span class="detail-value">
                                        <v-chip v-for="reason in leech.reasons" :key="reason" x-small color="error" outlined class="mr-1 mb-1">{{ leechReasonLabel(reason) }}</v-chip>
                                    </span></div>
                                    <div v-if="leech.suggestions && leech.suggestions.length" class="detail-row"><span class="detail-label">建议</span><span class="detail-value"><ul class="pl-4 mb-0"><li v-for="suggestion in leech.suggestions" :key="suggestion">{{ leechSuggestionLabel(suggestion) }}</li></ul></span></div>
                                    <v-alert v-if="['suspended', 'archived'].includes(detailTarget.lifecycle_state)" type="info" dense text class="mt-2 mb-0" border="left">
                                        该卡当前为「{{ stateLabel(detailTarget.lifecycle_state) }}」状态，不在复习队列中。遗忘诊断仍会基于历史数据计算。
                                    </v-alert>
                                </template>
                                <div v-else class="text-caption text--secondary py-2">暂无遗忘诊断数据。</div>
                            </div>
                        </v-tab-item>
                    </v-tabs-items>
                </v-card-text>
                <v-card-actions>
                    <v-btn text @click="closeDetail">关闭</v-btn>
                    <v-btn v-if="deepLinkSource" text color="primary" @click="$emit('return-to-report')"><v-icon left small>mdi-arrow-left</v-icon>返回学习报告</v-btn>
                    <v-spacer />
                    <v-btn text color="primary" @click="openSource">查看原文</v-btn>
                </v-card-actions>
            </v-card>
        </template>
        <div v-else class="d-flex align-center justify-center" style="min-height: 240px;">
            <div class="text-caption text--secondary">请从列表中选择一张卡片查看详情。</div>
        </div>
    </v-navigation-drawer>
</template>

<script>
import axios from 'axios';
import { actionColor, actionLabel, stateColor, stateLabel } from '../../services/ReviewCardLifecyclePresentation.js';
import {
    reasonLabel as leechReasonLabel,
    severityColor as leechSeverityColor,
    severityText as leechSeverityText,
    statusColor as leechStatusColor,
    statusLabel as leechStatusLabel,
    suggestionLabel as leechSuggestionLabel,
} from '../../services/SenseReviewLeechPresentation.js';

const DetailRow = {
    functional: true,
    props: ['label', 'value', 'valueClass'],
    render(h, context) {
        return h('div', { class: 'detail-row' }, [
            h('span', { class: 'detail-label' }, context.props.label),
            h('span', { class: ['detail-value', context.props.valueClass] }, String(context.props.value)),
        ]);
    },
};

export default {
    components: { DetailRow },
    props: {
        value: { type: Boolean, default: false },
        reviewCardId: { type: Number, default: null },
        deepLinkSource: { type: String, default: null },
    },
    data() {
        return {
            activeReviewCardId: null,
            detailTarget: null,
            cardInfo: null,
            detailLoading: false,
            detailError: '',
            detailRequestSeq: 0,
            detailTab: 'overview',
        };
    },
    computed: {
        drawerOpen: {
            get() { return this.value; },
            set(next) { if (!next) this.closeDetail(); },
        },
        activeTargetKey() {
            const reviewCardId = Number(this.reviewCardId);
            return this.value && Number.isInteger(reviewCardId) && reviewCardId > 0
                ? reviewCardId
                : null;
        },
        reviewLogs() { return this.cardInfo?.review_logs?.items || []; },
        reviewLogsLimit() { return this.cardInfo?.review_logs?.limit || 20; },
        lifecycleEvents() { return this.cardInfo?.lifecycle_events?.items || []; },
        lifecycleEventsLimit() { return this.cardInfo?.lifecycle_events?.limit || 20; },
        leech() { return this.cardInfo?.leech || null; },
    },
    watch: {
        activeTargetKey: {
            immediate: true,
            handler(reviewCardId) {
                if (reviewCardId === null) {
                    this.clearDetailState();
                    return;
                }
                this.activeReviewCardId = reviewCardId;
                this.detailTarget = null;
                this.cardInfo = null;
                this.detailError = '';
                this.detailTab = 'overview';
                this.loadCardInfo(reviewCardId);
            },
        },
    },
    methods: {
        actionColor,
        actionLabel,
        stateColor,
        stateLabel,
        leechReasonLabel,
        leechSeverityColor,
        leechSeverityText,
        leechStatusColor,
        leechStatusLabel,
        leechSuggestionLabel,
        loadCardInfo(reviewCardId) {
            const seq = ++this.detailRequestSeq;
            this.detailLoading = true;
            axios.get('/review-cards/manage/' + reviewCardId + '/detail')
                .then((response) => {
                    if (seq !== this.detailRequestSeq) return;
                    const data = response.data || {};
                    this.detailTarget = data;
                    this.cardInfo = data.card_info || null;
                    this.detailError = '';
                    this.$emit('detail-loaded', reviewCardId);
                })
                .catch((error) => {
                    if (seq !== this.detailRequestSeq) return;
                    this.detailError = '加载卡片详情失败：' + (error.response?.data?.message || error.message);
                    this.cardInfo = null;
                    this.$emit('detail-load-error', reviewCardId);
                })
                .finally(() => {
                    if (seq !== this.detailRequestSeq) return;
                    this.detailLoading = false;
                });
        },
        closeDetail() {
            this.clearDetailState();
            this.$emit('input', false);
            this.$emit('close');
        },
        clearDetailState() {
            this.detailRequestSeq++;
            this.activeReviewCardId = null;
            this.detailTarget = null;
            this.cardInfo = null;
            this.detailLoading = false;
            this.detailError = '';
            this.detailTab = 'overview';
        },
        openSource() {
            this.$emit('open-source', this.detailTarget);
            this.closeDetail();
        },
        displayValue(value, fallback = '—') { return value === null || value === undefined || value === '' ? fallback : value; },
        formatDateTime(value) {
            if (!value) return '—';
            return new Date(value).toLocaleString('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
        },
        formatFsrsNumber(value) {
            const number = Number(value);
            return value === null || value === undefined || value === '' || Number.isNaN(number) ? '—' : number.toFixed(2);
        },
        sourceKindLabel(kind) {
            return { chapter: '章节原文', occurrence_chapter: '出现章节', card_example: '仅有例句', missing: '缺失' }[kind] || kind || '—';
        },
        detailSourceClass(item) {
            if (item?.source_display_status === 'missing') return 'error--text';
            if (item?.source_display_status === 'card_example_only') return 'warning--text text--darken-1';
            return '';
        },
        sourceStatusLabel(item) {
            if (item?.source_display_status === 'missing') return '是（无例句无原文）';
            if (item?.source_display_status === 'card_example_only') return '仅保存例句（未定位原章节）';
            return '否（已定位原文）';
        },
        logRatingColor(rating) {
            return { again: 'red', hard: 'orange', good: 'green', easy: 'blue' }[rating] || 'grey';
        },
    },
};
</script>

<style scoped>
.detail-content { padding-top: 4px; }
.detail-section { margin-bottom: 4px; }
.detail-section-title { font-size: .75rem; font-weight: 700; color: rgba(0, 0, 0, .54); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 8px; }
.detail-row { display: flex; align-items: baseline; padding: 4px 0; border-bottom: 1px solid #f5f5f5; }
.detail-label { flex: 0 0 100px; font-size: .8rem; color: rgba(0, 0, 0, .54); white-space: nowrap; }
.detail-value { flex: 1; font-size: .85rem; color: rgba(0, 0, 0, .87); word-break: break-word; }
.log-entry { background: #fafafa; border-radius: 4px; border: 1px solid #f0f0f0; }
</style>
