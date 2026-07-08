<template>
    <div
        v-if="result"
        class="mt-4 pa-3 rounded"
        style="border: 1px solid var(--v-success-base);"
    >
        <div class="d-flex align-center mb-2">
            <v-icon x-small color="success" class="mr-1">mdi-check-circle</v-icon>
            <span class="text-subtitle-2 font-weight-medium">生成学习卡结果</span>
        </div>
        <v-alert
            :type="result.success ? 'success' : 'error'"
            dense
            text
            class="mb-2"
        >{{ result.message }}</v-alert>
        <div v-if="result.candidate_overview" class="mb-2 pa-2 rounded text-caption" style="background: var(--v-gray2-base);">
            <v-icon x-small class="mr-1">mdi-format-list-bulleted</v-icon>
            <span class="font-weight-medium">候选项总览：</span>
            共 {{ result.candidate_overview.total }} 项 ·
            已填写 {{ result.candidate_overview.filled }} 项 ·
            未填写 {{ result.candidate_overview.skipped_unfilled }} 项
            <div class="mt-1">
                <v-chip x-small color="success" class="mr-1">已填写 → 已提交生成</v-chip>
                <v-chip x-small color="warning">未填写 → 未提交、未生成、未删除</v-chip>
            </div>
            <div v-if="result.candidate_overview.skipped_unfilled > 0" class="mt-1 text--secondary">
                未填写的 {{ result.candidate_overview.skipped_unfilled }} 项不会生成学习卡，也不会被删除，可稍后再次确认。
            </div>
        </div>
        <div v-if="result.results" class="mb-2">
            <div class="text-caption mb-1">
                <v-chip x-small color="success" class="mr-2">创建 {{ result.results.summary.created_count }}</v-chip>
                <v-chip x-small color="warning" class="mr-2">跳过 {{ result.results.summary.skipped_count }}</v-chip>
                <v-chip x-small color="info" class="mr-2">重复 {{ result.results.summary.duplicate_count }}</v-chip>
                <v-chip x-small color="error" class="mr-2">失败 {{ result.results.summary.failed_count }}</v-chip>
            </div>
            <div v-if="result.results.created && result.results.created.length" class="mt-2">
                <div class="text-caption font-weight-bold mb-1">新建学习卡：</div>
                <div v-for="(item, idx) in result.results.created" :key="'created-' + idx" class="text-caption ml-2">
                    <v-icon x-small color="success" class="mr-1">mdi-card-plus-outline</v-icon>
                    {{ item.word }} → 释义 #{{ item.sense_id }} / 复习卡 #{{ item.review_card_id }}
                    <v-chip x-small :color="item.occurrence_created ? 'success' : 'warning'" class="ml-2">{{ item.source_binding_status }}</v-chip>
                    <v-chip v-if="item.pending_item_processed" x-small color="primary" class="ml-1">已从待解释移至已处理</v-chip>
                </div>
            </div>
            <div v-if="result.results.skipped && result.results.skipped.length" class="mt-2">
                <div class="text-caption font-weight-bold mb-1">跳过项：</div>
                <div v-for="(item, idx) in result.results.skipped" :key="'skipped-' + idx" class="text-caption ml-2">
                    <v-icon x-small color="warning" class="mr-1">mdi-skip-next</v-icon>
                    {{ item.word || '(空)' }} — {{ item.reason }}
                </div>
            </div>
            <div v-if="result.results.duplicate && result.results.duplicate.length" class="mt-2">
                <div class="text-caption font-weight-bold mb-1">重复项（已存在，未重复创建）：</div>
                <div v-for="(item, idx) in result.results.duplicate" :key="'dup-' + idx" class="text-caption ml-2">
                    <v-icon x-small color="info" class="mr-1">mdi-content-duplicate</v-icon>
                    {{ item.word }} → 释义 #{{ item.sense_id }}
                    <v-chip x-small :color="item.occurrence_created ? 'success' : 'warning'" class="ml-2">{{ item.source_binding_status }}</v-chip>
                    <v-chip v-if="item.pending_item_processed" x-small color="primary" class="ml-1">已从待解释移至已处理</v-chip>
                </div>
            </div>
            <div v-if="result.results.failed && result.results.failed.length" class="mt-2">
                <div class="text-caption font-weight-bold mb-1">失败项：</div>
                <div v-for="(item, idx) in result.results.failed" :key="'failed-' + idx" class="text-caption ml-2">
                    <v-icon x-small color="error" class="mr-1">mdi-alert-circle</v-icon>
                    {{ item.word || '(空)' }} — {{ item.reason }}
                </div>
            </div>
        </div>
        <div class="d-flex align-center mt-3">
            <v-btn small color="primary" @click="$emit('go-to-sense-reviews')">
                <v-icon x-small class="mr-1">mdi-school</v-icon>
                进入 /reviews/senses 复习
            </v-btn>
            <v-spacer />
            <v-btn small text @click="$emit('dismiss')">关闭结果</v-btn>
        </div>
        <v-alert type="info" dense text class="mt-2 mb-0">
            这不是 AI 自动调用，是你粘贴 AI 返回内容后的人工确认生成。
        </v-alert>
    </div>
</template>

<script>
/**
 * AiStudyCardGenerateCardsResult
 * ===============================
 * Shared V5 "生成学习卡结果" panel used by both VocabularySideBox (wide
 * screen) and VocabularyBox (narrow screen fallback).
 *
 * Responsibilities:
 *   - Render the result payload returned by `/ai-study-card/generate-cards`:
 *     success/error alert, candidate overview (total/filled/skipped_unfilled),
 *     created / skipped / duplicate / failed counts,
 *     per-created-item sense_id / review_card_id / source_binding_status,
 *     "进入 /reviews/senses 复习" entry, "关闭结果" button, and the safety
 *     copy "这不是 AI 自动调用".
 *   - Emit `go-to-sense-reviews` when user clicks the entry button.
 *   - Emit `dismiss` when user clicks "关闭结果".
 *
 * The panel does NOT call any backend endpoint, does NOT call AI, does NOT
 * write ReviewLog/FSRS/ReviewCard, and does NOT know about
 * VocabularySideBox / VocabularyBox internals. The parent owns the result
 * payload and the navigation action.
 *
 * GM52-AIStudyCardV5-DesktopArchitectureConvergence-1000-1.
 */
export default {
    name: 'AiStudyCardGenerateCardsResult',
    props: {
        // The result payload from /ai-study-card/generate-cards.
        // Expected shape: {
        //   success, message,
        //   candidate_overview: { total, filled, skipped_unfilled }, // attached by workflow
        //   results: { summary, created, skipped, duplicate, failed },
        //   safety_flags
        // }.
        result: {
            type: Object,
            default: null,
        },
    },
};
</script>
