<template>
    <v-dialog
        v-model="visible"
        :fullscreen="$vuetify.breakpoint.xsOnly"
        max-width="1040"
        scrollable
    >
        <v-card class="reading-sense-verification-dialog">
            <v-card-title class="verification-header">
                <div>
                    <div class="text-h6">词义核对列表</div>
                    <div class="caption text--secondary">
                        待核对 {{ summary.pending }} · 已核对 {{ summary.verified }} · 已排除 {{ summary.excluded }}
                    </div>
                </div>
                <v-spacer />
                <v-btn icon aria-label="关闭词义核对列表" @click="visible = false"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>

            <v-progress-linear v-if="loading" indeterminate color="primary" />
            <v-card-text class="pt-4 verification-list">
                <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>
                <v-alert dense text type="info" class="mb-4">
                    这里核对的是“这次文章里的这个位置对应哪个词义”。核对本身不会评分，也不会修改 FSRS。
                </v-alert>
                <v-alert v-if="!resolutionEnabled" dense outlined type="warning" class="mb-4">
                    当前只展示服务器返回的核对建议；保存词义绑定会在正式后端契约接通后启用。本页不会假装保存成功。
                </v-alert>

                <div v-if="!loading && !items.length" class="text-center text--secondary py-10">
                    当前没有需要核对的词义。
                </div>

                <v-card
                    v-for="item in items"
                    :key="item.occurrence_id"
                    outlined
                    class="mb-3 pa-3 verification-item"
                    :data-occurrence-id="item.occurrence_id"
                >
                    <div class="d-flex align-start flex-wrap verification-item-heading">
                        <div class="mr-3">
                            <strong class="text-body-1">{{ item.surface || item.phrase || item.lemma }}</strong>
                            <div class="caption text--secondary">
                                {{ item.lemma || '' }}<span v-if="item.pos"> · {{ item.pos }}</span>
                            </div>
                        </div>
                        <v-chip x-small outlined class="mr-2 mb-1">{{ decisionLabel(item.result) }}</v-chip>
                        <v-chip v-if="item.confidence" x-small outlined class="mr-2 mb-1">{{ confidenceLabel(item.confidence) }}</v-chip>
                        <v-chip v-if="isTrustAiVerified(item)" x-small color="success" class="mb-1">AI 已核对</v-chip>
                        <v-spacer />
                        <v-chip x-small :color="stateColor(state(item))" text-color="white">{{ stateLabel(state(item)) }}</v-chip>
                    </div>

                    <div v-if="item.source_sentence" class="body-2 my-3 verification-sentence">
                        {{ item.source_sentence }}
                    </div>

                    <div v-if="item.sense_zh || item.sense_en || item.new_sense" class="body-2 mb-3">
                        <div v-if="suggestedSenseZh(item)"><strong>AI 中文：</strong>{{ suggestedSenseZh(item) }}</div>
                        <div v-if="suggestedSenseEn(item)" class="text--secondary"><strong>English：</strong>{{ suggestedSenseEn(item) }}</div>
                    </div>

                    <v-alert
                        v-if="!isReadingSenseWordTarget(item)"
                        dense
                        text
                        type="info"
                        class="mb-3"
                    >词组只展示 AI 释义，不进入 WordSense 绑定或被动复习。</v-alert>

                    <v-select
                        v-if="isReadingSenseWordTarget(item) && candidateOptions(item).length"
                        v-model="selectedSenseIds[item.occurrence_id]"
                        :items="candidateOptions(item)"
                        label="改选已学词义"
                        outlined
                        dense
                        hide-details="auto"
                        class="mb-3"
                        :disabled="!resolutionEnabled || busyOccurrenceId === item.occurrence_id"
                    />

                    <div v-if="isReadingSenseWordTarget(item)" class="d-flex flex-wrap verification-actions">
                        <v-btn
                            v-if="candidateOptions(item).length"
                            small
                            depressed
                            color="primary"
                            class="mr-2 mb-2"
                            :disabled="!resolutionEnabled || busyOccurrenceId === item.occurrence_id || !selectedSenseIds[item.occurrence_id]"
                            @click="resolve(item, 'match_existing')"
                        >确认这是所选已学词义</v-btn>
                        <v-btn
                            small
                            outlined
                            class="mr-2 mb-2"
                            :disabled="!resolutionEnabled || busyOccurrenceId === item.occurrence_id"
                            @click="resolve(item, 'new_sense')"
                        >标记为新词义</v-btn>
                        <v-btn
                            small
                            text
                            class="mb-2"
                            :disabled="!resolutionEnabled || busyOccurrenceId === item.occurrence_id"
                            @click="resolve(item, 'exclude')"
                        >本次不计入被动复习</v-btn>
                    </div>
                </v-card>
            </v-card-text>

            <v-card-actions class="verification-footer">
                <v-btn text :disabled="loading" @click="$emit('refresh')"><v-icon small left>mdi-refresh</v-icon>刷新</v-btn>
                <v-spacer />
                <v-btn color="primary" text @click="visible = false">返回阅读</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    import {
        buildReadingSenseResolutionIntent,
        candidateOptions,
        isTrustAiVerified,
        isReadingSenseWordTarget,
        readingSenseConfidenceLabel,
        readingSenseDecisionLabel,
        readingSenseVerificationState,
        readingSenseVerificationSummary,
    } from '../../services/ReadingSenseVerificationPolicy.js';

    export default {
        name: 'ReadingSenseVerificationDialog',
        props: {
            value: { type: Boolean, default: false },
            items: { type: Array, default: () => [] },
            loading: { type: Boolean, default: false },
            error: { type: String, default: '' },
            busyOccurrenceId: { type: String, default: '' },
            resolutionEnabled: { type: Boolean, default: false },
        },
        data() {
            return { selectedSenseIds: {} };
        },
        computed: {
            visible: {
                get() { return this.value; },
                set(value) { this.$emit('input', value); },
            },
            summary() {
                return readingSenseVerificationSummary(this.items);
            },
        },
        watch: {
            items: {
                immediate: true,
                handler(items) {
                    const next = {};
                    for (const item of items || []) {
                        const preferred = item.evidence?.word_sense_id
                            || item.verification?.word_sense_id
                            || item.matched_word_sense_id
                            || candidateOptions(item)[0]?.value
                            || null;
                        if (preferred) next[item.occurrence_id] = preferred;
                    }
                    this.selectedSenseIds = next;
                },
            },
        },
        methods: {
            candidateOptions,
            isTrustAiVerified,
            isReadingSenseWordTarget,
            decisionLabel: readingSenseDecisionLabel,
            confidenceLabel: readingSenseConfidenceLabel,
            state: readingSenseVerificationState,
            stateLabel(state) {
                return { pending: '待核对', verified: '已核对', excluded: '已排除' }[state] || '待核对';
            },
            stateColor(state) {
                return { pending: 'warning', verified: 'success', excluded: 'grey' }[state] || 'warning';
            },
            suggestedSenseZh(item) {
                return item.sense_zh || item.new_sense?.sense_zh || '';
            },
            suggestedSenseEn(item) {
                return item.sense_en || item.new_sense?.sense_en || '';
            },
            resolve(item, action) {
                if (!this.resolutionEnabled) return;
                const intent = buildReadingSenseResolutionIntent(
                    item,
                    action,
                    this.selectedSenseIds[item.occurrence_id],
                );
                if (intent) this.$emit('resolve', intent);
            },
        },
    };
</script>

<style scoped>
    .verification-header,
    .verification-footer {
        position: sticky;
        z-index: 2;
        background: var(--v-foreground-base);
    }
    .verification-header { top: 0; }
    .verification-footer { bottom: 0; }
    .verification-list { max-height: min(72vh, 780px); }
    .verification-sentence { line-height: 1.55; }
    .verification-actions { gap: 2px; }
    @media (max-width: 600px) {
        .verification-list { max-height: none; padding: 12px !important; }
        .verification-item { padding: 12px !important; }
        .verification-actions .v-btn { min-height: 44px; flex: 1 1 100%; margin-right: 0 !important; }
        .verification-footer { padding-bottom: calc(8px + env(safe-area-inset-bottom, 0px)); }
    }
</style>
