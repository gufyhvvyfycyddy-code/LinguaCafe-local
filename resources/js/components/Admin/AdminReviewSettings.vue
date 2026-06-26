<template>
    <div id="admin-review-settings">

        <div class="subheader mt-4">间隔重复系统</div>

        <!-- Zone 1: 复习目标 -->
        <v-card outlined class="rounded-lg mt-4">
            <v-card-title>复习目标</v-card-title>
            <v-card-subtitle>设置目标记忆保持率，控制复习频率。</v-card-subtitle>
            <v-card-text>
                <v-simple-table dense class="no-hover">
                    <tbody>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">Desired Retention</td>
                            <td class="py-2" style="min-width: 200px;">
                                <v-select
                                    v-model="fsrsDesiredRetention"
                                    :items="fsrsRetentionOptions"
                                    item-text="text"
                                    item-value="value"
                                    outlined
                                    dense
                                    hide-details
                                    style="max-width: 160px;"
                                />
                                <div class="mt-2 grey--text text--darken-1 caption">
                                    <span class="font-weight-bold">{{ fsrsDesiredRetentionText }}</span>
                                    <span v-if="retentionExplanation"> — {{ retentionExplanation }}</span>
                                    <v-chip v-if="isRecommended" x-small color="success" outlined class="ml-1">推荐默认值</v-chip>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td class="font-weight-bold pr-4 py-2">说明</td>
                            <td class="py-2 grey--text text--darken-1">
                                Desired retention 越高，复习负担越重。
                                本设置只影响之后评分产生的新到期时间，不会自动重排已有卡片。
                            </td>
                        </tr>
                    </tbody>
                </v-simple-table>

                <!-- 复习负担预估 -->
                <div class="mt-3 pa-3 rounded" style="background: #f5f7fa;">
                    <div class="font-weight-medium body-2 mb-1">每天大概要复习多少</div>
                    <div class="body-1 grey--text text--darken-1">{{ burdenEstimateMessage }}</div>
                    <div class="caption grey--text mt-1">粗略预估，仅帮助你感受负担，不会重排已有卡片。</div>
                </div>

                <v-card-actions class="px-0">
                    <v-spacer />
                    <v-btn
                        rounded
                        depressed
                        color="primary"
                        :disabled="fsrsSaving"
                        :loading="fsrsSaving"
                        @click="saveFsrsSettings"
                    >
                        保存 FSRS 设置
                    </v-btn>
                </v-card-actions>

                <div v-if="fsrsSaveStatus" class="mt-2 green--text text--darken-1 body-2">
                    {{ fsrsSaveStatus }}
                </div>
            </v-card-text>
        </v-card>

        <!-- Zone 2: 当前 FSRS 状态 -->
        <v-card outlined class="rounded-lg mt-4" :loading="statsLoading">
            <v-card-title>当前 FSRS 状态</v-card-title>
            <v-card-subtitle>仅统计当前语言下的词义复习卡，不包含旧单词卡。</v-card-subtitle>
            <v-card-text>
                <v-alert v-if="statsError" type="error" dense outlined class="mb-4">{{ statsError }}</v-alert>

                <div v-if="!statsError && fsrsStats.total === 0 && !statsLoading" class="text--secondary py-4 text-center">
                    当前没有词义复习卡。
                </div>

                <div v-if="!statsError && fsrsStats.total > 0">
                    <!-- Row 1: Summary -->
                    <div class="text-caption text--secondary mb-2">概况</div>
                    <v-row dense class="mb-4">
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.total }}</div>
                                <div class="text-caption text--secondary">总词义卡</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.enabled }}</div>
                                <div class="text-caption text--secondary">启用中</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.archived }}</div>
                                <div class="text-caption text--secondary">已归档</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.due }}</div>
                                <div class="text-caption text--secondary">当前到期</div>
                            </v-sheet>
                        </v-col>
                    </v-row>

                    <!-- Row 2: State Distribution -->
                    <div class="text-caption text--secondary mb-2">状态分布</div>
                    <v-row dense class="mb-4">
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.new }}</div>
                                <div class="text-caption text--secondary">新卡</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.learning }}</div>
                                <div class="text-caption text--secondary">学习中</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.review }}</div>
                                <div class="text-caption text--secondary">复习中</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.by_state.relearning }}</div>
                                <div class="text-caption text--secondary">重新学习</div>
                            </v-sheet>
                        </v-col>
                    </v-row>

                    <!-- Row 3: FSRS Proficiency -->
                    <div class="text-caption text--secondary mb-2">FSRS 熟练度</div>
                    <v-row dense class="mb-2">
                        <v-col cols="2">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ formatFloat(fsrsStats.average_stability) }}</div>
                                <div class="text-caption text--secondary">平均稳定度</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="2">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ formatFloat(fsrsStats.average_difficulty) }}</div>
                                <div class="text-caption text--secondary">平均难度</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="2">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.lapses_total }}</div>
                                <div class="text-caption text--secondary">总遗忘次数</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.reviewed_today }}</div>
                                <div class="text-caption text--secondary">今日已复习</div>
                            </v-sheet>
                        </v-col>
                        <v-col cols="3">
                            <v-sheet outlined rounded class="pa-3 text-center">
                                <div class="text-h6 font-weight-bold">{{ fsrsStats.reset_count }}</div>
                                <div class="text-caption text--secondary">今日重置</div>
                            </v-sheet>
                        </v-col>
                    </v-row>

                    <div class="mt-2 grey--text caption">
                        稳定度越高，表示记忆越稳定；难度越高，表示这张卡越难。
                    </div>
                </div>
            </v-card-text>
        </v-card>

        <!-- Zone 3: 高级工具 (collapsed by default) -->
        <v-expansion-panels flat class="mt-4">
            <v-expansion-panel>
                <v-expansion-panel-header>
                    高级工具
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <div class="text-caption grey--text text--darken-1 mb-3">
                        参数优化、卡片重排、手动参数、卡片重置等低频操作，需要时再打开。
                    </div>

                    <v-simple-table dense class="no-hover">
                        <tbody>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">自动优化参数</td>
                                <td class="py-2">
                                    <div>根据你的复习记录，帮 FSRS 算出更适合你的参数。</div>
                                    <div class="grey--text text--darken-1 caption mb-2">
                                        当前只是预览，不会修改 FSRS 调度。
                                    </div>
                                    <v-btn
                                        small
                                        outlined
                                        color="primary"
                                        :loading="fsrsOptimizationLoading"
                                        :disabled="fsrsOptimizationLoading"
                                        @click="runFsrsOptimizationPreflight"
                                    >
                                        根据我的复习记录优化
                                    </v-btn>
                                    <v-alert
                                        v-if="fsrsOptimizationMessage && !fsrsOptimizationPreview"
                                        dense
                                        outlined
                                        class="mt-3 mb-0"
                                        :type="fsrsOptimizationCanOptimize ? 'info' : 'warning'"
                                    >
                                        {{ fsrsOptimizationMessage }}
                                    </v-alert>

                                    <!-- 优化预览卡片 -->
                                    <div v-if="fsrsOptimizationPreview && fsrsOptimizationPreview.preview_available" class="mt-4">
                                        <v-alert
                                            dense
                                            outlined
                                            type="success"
                                            class="mb-0"
                                        >
                                            {{ fsrsOptimizationPreview.message }}
                                        </v-alert>

                                        <v-card outlined class="rounded-lg mt-3">
                                            <v-card-title class="subtitle-1">参数优化预览</v-card-title>
                                            <v-card-text>

                                                <div class="body-2 grey--text text--darken-1 mb-3">
                                                    系统已经根据你的复习记录算出一组个性化参数，当前只是预览，不会保存，也不会重排已有卡片。
                                                </div>

                                                <v-simple-table dense class="no-hover mb-4">
                                                    <tbody>
                                                        <tr>
                                                            <td class="font-weight-medium pr-4 py-1">用于计算的复习记录</td>
                                                            <td class="py-1">{{ fsrsOptimizationPreview.review_count }} 条</td>
                                                        </tr>
                                                        <tr>
                                                            <td class="font-weight-medium pr-4 py-1">涉及词义卡</td>
                                                            <td class="py-1">{{ fsrsOptimizationPreview.card_count }} 张</td>
                                                        </tr>
                                                        <tr>
                                                            <td class="font-weight-medium pr-4 py-1">参数数量</td>
                                                            <td class="py-1">{{ fsrsOptimizationPreview.parameter_count }} 个</td>
                                                        </tr>
                                                    </tbody>
                                                </v-simple-table>

                                                <v-simple-table dense class="no-hover mb-4">
                                                    <tbody>
                                                        <tr>
                                                            <td class="font-weight-medium pr-4 py-1">当前参数数量</td>
                                                            <td class="py-1">{{ fsrsOptimizationPreview.current_parameters.length }} 个</td>
                                                        </tr>
                                                        <tr>
                                                            <td class="font-weight-medium pr-4 py-1">优化后参数数量</td>
                                                            <td class="py-1">{{ fsrsOptimizationPreview.optimized_parameters.length }} 个</td>
                                                        </tr>
                                                        <tr>
                                                            <td class="font-weight-medium pr-4 py-1">发生变化的参数</td>
                                                            <td class="py-1">{{ paramDiffSummary.changedCount }} 个</td>
                                                        </tr>
                                                        <tr>
                                                            <td class="font-weight-medium pr-4 py-1">最大变化幅度</td>
                                                            <td class="py-1">{{ paramDiffSummary.maxDiffText }}</td>
                                                        </tr>
                                                    </tbody>
                                                </v-simple-table>

                                                <v-expansion-panels flat>
                                                    <v-expansion-panel>
                                                        <v-expansion-panel-header class="body-2">
                                                            查看参数明细
                                                        </v-expansion-panel-header>
                                                        <v-expansion-panel-content>
                                                            <div class="pt-2" style="overflow-x: auto;">
                                                                <table class="v-data-table v-data-table--dense theme--light" style="width: 100%;">
                                                                    <thead>
                                                                        <tr>
                                                                            <th class="text-left px-2 py-1">参数</th>
                                                                            <th class="text-right px-2 py-1">当前值</th>
                                                                            <th class="text-right px-2 py-1">优化后</th>
                                                                            <th class="text-right px-2 py-1">变化</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr v-for="(row, idx) in paramComparisonRows" :key="idx">
                                                                            <td class="text-left px-2 py-1">{{ row.label }}</td>
                                                                            <td class="text-right px-2 py-1" style="font-family: monospace; font-size: 12px;">{{ row.currentText }}</td>
                                                                            <td class="text-right px-2 py-1" style="font-family: monospace; font-size: 12px;">{{ row.optimizedText }}</td>
                                                                            <td class="text-right px-2 py-1" :style="{ color: row.changed ? (row.diff > 0 ? '#2e7d32' : '#c62828') : '' }" style="font-family: monospace; font-size: 12px;">{{ row.diffText }}</td>
                                                                        </tr>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </v-expansion-panel-content>
                                                    </v-expansion-panel>
                                                </v-expansion-panels>

                                                <v-alert
                                                    dense
                                                    outlined
                                                    type="info"
                                                    class="mt-4 mb-2"
                                                >
                                                    这次不会改变你的复习安排。
                                                </v-alert>
                                            <v-alert
                                                dense
                                                outlined
                                                type="info"
                                                class="mb-2"
                                            >
                                                参数保存后会替换当前 FSRS 参数，确认后不可撤销。
                                            </v-alert>
                                                <v-alert
                                                    dense
                                                    outlined
                                                    type="info"
                                                    class="mb-0"
                                                >
                                                    应用后，只会影响之后新的复习评分；不会重排已有卡片。
                                                </v-alert>

                                                <div class="mt-4 text-center">
                                                    <v-btn
                                                        color="success"
                                                        :loading="fsrsOptimizationConfirmLoading"
                                                        :disabled="fsrsOptimizationConfirmLoading || fsrsOptimizationApplySuccess"
                                                        @click="confirmApplyFsrsParameters"
                                                    >
                                                        确认应用优化参数
                                                    </v-btn>
                                                </div>

                                            </v-card-text>
                                        </v-card>
                                    </div>

                                    <v-alert
                                        v-if="fsrsOptimizationApplySuccess"
                                        dense
                                        outlined
                                        type="success"
                                        class="mt-3 mb-0"
                                    >
                                        {{ fsrsOptimizationApplySuccess }}
                                    </v-alert>
                                </td>
                            </tr>
                            <!-- D.4-b: 重排已有卡片预览 -->
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">重排已有卡片</td>
                                <td class="py-2">
                                    <div class="body-2">
                                        保存优化参数后，新的复习评分已经会使用优化参数。这个预览可以告诉你：如果以后确认重排旧卡片，到期时间会怎么变化。
                                    </div>

                                    <v-btn
                                        small
                                        outlined
                                        color="primary"
                                        class="mt-2"
                                        :loading="fsrsReschedulePreviewLoading"
                                        :disabled="fsrsReschedulePreviewLoading"
                                        @click="previewFsrsRescheduleImpact"
                                    >
                                        看看重排后卡片到期日会怎么变
                                    </v-btn>

                                    <!-- Error -->
                                    <v-alert
                                        v-if="fsrsReschedulePreviewError"
                                        dense
                                        outlined
                                        type="error"
                                        class="mt-3 mb-0"
                                    >
                                        {{ fsrsReschedulePreviewError }}
                                    </v-alert>

                                    <!-- Warning (preview_available=false) -->
                                    <div v-if="fsrsReschedulePreview && !fsrsReschedulePreview.preview_available">
                                        <v-alert
                                            v-for="(w, wi) in fsrsReschedulePreview.warnings"
                                            :key="'warn-' + wi"
                                            dense
                                            outlined
                                            type="warning"
                                            class="mt-3 mb-0"
                                        >
                                            {{ w }}
                                        </v-alert>
                                    </div>

                                    <!-- Preview result (preview_available=true) -->
                                    <div v-if="fsrsReschedulePreview && fsrsReschedulePreview.preview_available" class="mt-4">
                                        <!-- Empty candidates -->
                                        <div v-if="fsrsReschedulePreview.total_candidates === 0">
                                            <v-alert dense outlined type="info" class="mb-0">
                                                当前没有符合条件的旧卡片可预览。
                                            </v-alert>
                                            <div class="caption grey--text mt-2">
                                                确认条件：sense card + review 状态 + 已 confirmed WordSense + 有 FSRS 记忆状态。
                                            </div>
                                        </div>

                                        <!-- Results -->
                                        <div v-if="fsrsReschedulePreview.total_candidates > 0">
                                            <v-card outlined class="rounded-lg">
                                                <v-card-text class="pa-4">
                                                    <div class="font-weight-medium subtitle-2 mb-3">重排预览统计</div>

                                                    <!-- Row 1: Core counts -->
                                                    <v-row dense class="mb-2">
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.total_candidates }}</div>
                                                                <div class="text-caption text--secondary">可预览旧卡片</div>
                                                            </v-sheet>
                                                        </v-col>
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.total_changed }}</div>
                                                                <div class="text-caption text--secondary">到期时间会变化</div>
                                                            </v-sheet>
                                                        </v-col>
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.skipped_count }}</div>
                                                                <div class="text-caption text--secondary">跳过</div>
                                                            </v-sheet>
                                                            <div v-if="fsrsReschedulePreview.skipped_count > 0" class="caption grey--text text--darken-1 mt-1">
                                                                跳过通常表示卡片缺少完整 FSRS 到期信息或预览计算失败；本次不会修改这些卡片。
                                                            </div>
                                                        </v-col>
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.summary.unchanged }}</div>
                                                                <div class="text-caption text--secondary">不变</div>
                                                            </v-sheet>
                                                        </v-col>
                                                    </v-row>

                                                    <!-- Row 2: Movement and due -->
                                                    <v-row dense class="mb-2">
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.summary.will_move_earlier }}</div>
                                                                <div class="text-caption text--secondary">会提前到期</div>
                                                            </v-sheet>
                                                        </v-col>
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.summary.will_move_later }}</div>
                                                                <div class="text-caption text--secondary">会延后到期</div>
                                                            </v-sheet>
                                                        </v-col>
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.summary.currently_due }}</div>
                                                                <div class="text-caption text--secondary">当前已到期</div>
                                                            </v-sheet>
                                                        </v-col>
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ fsrsReschedulePreview.summary.due_today_after_reschedule }}</div>
                                                                <div class="text-caption text--secondary">重排后今天到期</div>
                                                            </v-sheet>
                                                        </v-col>
                                                    </v-row>

                                                    <!-- Row 3: Max changes -->
                                                    <v-row dense class="mb-3">
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ formatDaysChange(fsrsReschedulePreview.summary.max_earlier_days) }}</div>
                                                                <div class="text-caption text--secondary">最大提前</div>
                                                            </v-sheet>
                                                        </v-col>
                                                        <v-col cols="3">
                                                            <v-sheet outlined rounded class="pa-2 text-center">
                                                                <div class="text-h6 font-weight-bold">{{ formatDaysChange(fsrsReschedulePreview.summary.max_later_days) }}</div>
                                                                <div class="text-caption text--secondary">最大延后</div>
                                                            </v-sheet>
                                                        </v-col>
                                                    </v-row>

                                                    <!-- Newly due today risk -->
                                                    <v-alert
                                                        v-if="fsrsReschedulePreview.summary.newly_due_today > 0"
                                                        dense
                                                        outlined
                                                        type="warning"
                                                        class="mb-3"
                                                    >
                                                        预览显示会新增 {{ fsrsReschedulePreview.summary.newly_due_today }} 张今天到期卡。正式重排前请确认你能接受复习量变化。
                                                    </v-alert>

                                                    <!-- Preview disclaimer -->
                                                    <v-alert dense outlined type="info" class="mb-2">
                                                        这是预览，不会修改任何卡片。
                                                    </v-alert>
                                                    <v-alert dense outlined type="info" class="mb-0">
                                                        正式重排会在后续步骤单独开放确认按钮。
                                                    </v-alert>

                                                    <!-- Samples table -->
                                                    <div v-if="hasReschedulePreviewSamples" class="mt-4">
                                                        <div class="font-weight-medium subtitle-2 mb-2">样例（最多 20 条）</div>
                                                        <v-simple-table dense class="no-hover">
                                                            <thead>
                                                                <tr>
                                                                    <th class="text-left">词</th>
                                                                    <th class="text-left">释义</th>
                                                                    <th class="text-left">当前到期</th>
                                                                    <th class="text-left">预览到期</th>
                                                                    <th class="text-left">变化天数</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr v-for="(sample, si) in fsrsReschedulePreview.samples" :key="si">
                                                                    <td>{{ sample.lemma }}</td>
                                                                    <td>{{ sample.sense_zh || sample.sense_en || '—' }}</td>
                                                                    <td>{{ formatDate(sample.current_due_at) }}</td>
                                                                    <td>{{ formatDate(sample.preview_due_at) }}</td>
                                                                    <td :class="sample.days_change < 0 ? 'green--text' : (sample.days_change > 0 ? 'orange--text' : '')">
                                                                        {{ formatDaysChange(sample.days_change) }}
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </v-simple-table>
                                                    </div>
                                                </v-card-text>
                                            </v-card>

                                            <!-- D.4-c: Confirm reschedule button -->
                                            <div class="mt-4">
                                                <v-alert
                                                    v-if="fsrsRescheduleApplySuccess"
                                                    dense
                                                    outlined
                                                    type="success"
                                                    class="mb-3"
                                                >
                                                    {{ fsrsRescheduleApplySuccess }}
                                                </v-alert>

                                                <v-alert
                                                    v-if="fsrsRescheduleConfirmError"
                                                    dense
                                                    outlined
                                                    type="error"
                                                    class="mb-3"
                                                >
                                                    {{ fsrsRescheduleConfirmError }}
                                                </v-alert>

                                                <v-btn
                                                    color="warning"
                                                    outlined
                                                    :loading="fsrsRescheduleConfirmLoading"
                                                    :disabled="fsrsRescheduleConfirmLoading || !!fsrsRescheduleApplySuccess || !fsrsReschedulePreview"
                                                    @click="openRescheduleConfirmDialog"
                                                >
                                                    确认重排这些卡片
                                                </v-btn>

                                                <div class="caption grey--text mt-2">
                                                    确认后系统会重新计算这些卡片的到期日。若发现不合适，可在 7 天内撤销上次重排；已复习的卡片不会恢复。不会产生复习记录，不影响复习计数。
                                                </div>
                                            </div>

                                            <!-- D.4-d-c: Undo alerts (outside v-if so always visible) -->
                                            <v-alert
                                                v-if="fsrsRescheduleUndoError"
                                                dense outlined type="error"
                                                class="mb-3 mt-4"
                                            >{{ fsrsRescheduleUndoError }}</v-alert>

                                            <v-alert
                                                v-if="fsrsRescheduleUndoSuccess"
                                                dense outlined type="success"
                                                class="mb-3 mt-4"
                                            >{{ fsrsRescheduleUndoSuccess }}</v-alert>

                                            <!-- D.4-d-c: Undo reschedule button (hidden after success) -->
                                            <div class="mt-4" v-if="!fsrsRescheduleUndoSuccess">
                                                <v-btn
                                                    color="secondary"
                                                    outlined
                                                    :loading="fsrsRescheduleUndoLoading"
                                                    :disabled="fsrsRescheduleUndoLoading || fsrsRescheduleConfirmLoading"
                                                    @click="openUndoDialog"
                                                >
                                                    撤销上次重排
                                                </v-btn>

                                                <div class="caption grey--text mt-2">
                                                    7 天内可撤销，已复习的卡片不会恢复。
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">参数来源</td>
                                <td class="py-2">
                                    <!-- Default parameters -->
                                    <div v-if="fsrsParameterSource === 'default'">
                                        <div>当前使用默认参数。</div>
                                        <div class="grey--text text--darken-1 caption">
                                            还没有保存过优化参数。
                                        </div>
                                        <div class="grey--text text--darken-1 caption mt-1">
                                            参数数量：{{ fsrsParameterCount }} 个
                                        </div>
                                    </div>

                                    <!-- Optimized parameters -->
                                    <div v-else-if="fsrsParameterSource === 'optimized'">
                                        <v-chip color="success" small label class="mb-2">
                                            正在优化参数
                                        </v-chip>
                                        <div class="grey--text text--darken-1 caption">
                                            最近优化时间：{{ formatDate(fsrsParameterLastOptimizedAt) }}
                                        </div>
                                        <div class="grey--text text--darken-1 caption mt-1">
                                            参数数量：{{ fsrsParameterCount }} 个
                                        </div>
                                        <div class="grey--text text--darken-1 caption mt-1">
                                            已保存优化参数；之后新的复习评分将使用这组参数。已有卡片不会自动重排。
                                        </div>
                                    </div>

                                    <!-- Unknown/custom parameters -->
                                    <div v-else>
                                        <div>{{ fsrsParameterSourceLabel }}</div>
                                        <div v-if="fsrsParameterWarning" class="orange--text text--darken-2 caption mt-1">
                                            {{ fsrsParameterWarning }}
                                        </div>
                                        <div class="grey--text text--darken-1 caption mt-1">
                                            参数数量：{{ fsrsParameterCount }} 个
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">手动编辑参数</td>
                                <td class="py-2">
                                    <div>暂未开放。</div>
                                    <div class="grey--text text--darken-1 caption">
                                        后续可以作为高级功能单独评估。手动编辑会影响复习安排，需要强提醒和单独确认。
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td class="font-weight-bold pr-4 py-2" style="vertical-align: middle;">卡片重置</td>
                                <td class="py-2">
                                    <div>已在复习卡管理页开放。</div>
                                    <div class="grey--text text--darken-1 caption">
                                        如果想让某张卡重新开始学习，请在管理页使用"重置"。
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </v-simple-table>

                    <v-card-actions class="px-0">
                        <v-btn
                            small
                            outlined
                            color="primary"
                            @click="goToManagePage"
                        >
                            前往复习卡管理页
                        </v-btn>
                    </v-card-actions>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <!-- Legacy SRS Settings (collapsed by default) -->
        <v-expansion-panels flat class="mt-4">
            <v-expansion-panel>
                <v-expansion-panel-header>
                    旧版 SRS 设置（仅影响旧单词卡和短语）
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <v-alert border="left" type="warning" color="warning" class="mb-4">
                        这些设置不会影响词义复习卡。<br>
                        当前词义复习卡使用 FSRS 调度。<br>
                        仅在仍使用旧单词复习或短语复习时才需要修改这里。
                    </v-alert>

                    <v-card outlined class="rounded-lg" :loading="!reviewIntervals.length">
                        <v-card-text>
                            <v-simple-table dense class="no-hover no-lines">
                                <tbody>
                                    <tr v-for="(interval, index) in reviewIntervals" :key="index">
                                        <td class="pt-4">
                                            等级 {{ interval.name }}：
                                        </td>
                                        <td class="pt-4">
                                            <v-text-field
                                                v-model="interval.values"
                                                filled
                                                rounded
                                                dense
                                                hide-details
                                                :disabled="!index"
                                                @change="reviewIntervalChanged($event, index)"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </v-simple-table>
                        </v-card-text>

                        <v-card-actions>
                            <v-spacer />
                            <v-btn
                                rounded
                                depressed
                                color="primary"
                                :disabled="!reviewIntervals.length || saving"
                                :loading="saving"
                                @click="saveSettings"
                            >
                                保存
                            </v-btn>
                        </v-card-actions>
                    </v-card>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <!-- D.4-c: First confirmation dialog -->
        <v-dialog v-model="fsrsRescheduleConfirmDialog" max-width="480" persistent>
            <v-card>
                <v-card-title class="warning--text text--darken-1">
                    <v-icon left color="warning">mdi-alert-circle-outline</v-icon>
                    确认重排卡片
                </v-card-title>
                <v-card-text>
                    <div class="body-2 mb-3 grey--text text--darken-1">
                        请确认以下统计信息，确认后将不可撤销：
                    </div>

                    <v-simple-table dense class="no-hover mb-3">
                        <tbody>
                            <tr>
                                <td class="font-weight-medium pr-4 py-1">可预览旧卡片</td>
                                <td class="py-1">{{ fsrsReschedulePreview ? fsrsReschedulePreview.total_candidates : '—' }}</td>
                            </tr>
                            <tr>
                                <td class="font-weight-medium pr-4 py-1">到期时间会变化</td>
                                <td class="py-1">{{ fsrsReschedulePreview ? fsrsReschedulePreview.total_changed : '—' }}</td>
                            </tr>
                            <tr>
                                <td class="font-weight-medium pr-4 py-1">重排后今天到期</td>
                                <td class="py-1">{{ fsrsReschedulePreview && fsrsReschedulePreview.summary ? fsrsReschedulePreview.summary.due_today_after_reschedule : '—' }}</td>
                            </tr>
                            <tr>
                                <td class="font-weight-medium pr-4 py-1">跳过</td>
                                <td class="py-1">{{ fsrsReschedulePreview ? fsrsReschedulePreview.skipped_count : '—' }}</td>
                            </tr>
                        </tbody>
                    </v-simple-table>

                    <v-alert dense outlined type="warning" class="mb-3">
                        此操作会修改卡片的到期日，不会创建复习记录，不影响复习计数。确认后不可撤销。
                    </v-alert>

                    <v-alert v-if="fsrsRescheduleCountdown > 0" dense outlined type="info" class="mb-0">
                        请等待 {{ fsrsRescheduleCountdown }} 秒后确认
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="fsrsRescheduleConfirmDialog = false; stopCountdown();">
                        取消
                    </v-btn>
                    <v-btn
                        color="warning"
                        :disabled="fsrsRescheduleCountdown > 0 || fsrsRescheduleConfirmLoading"
                        :loading="fsrsRescheduleConfirmLoading"
                        @click="confirmReschedule"
                    >
                        继续确认
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- D.4-c: High-risk confirmation dialog -->
        <v-dialog v-model="fsrsRescheduleRiskDialog" max-width="480" persistent>
            <v-card>
                <v-card-title class="error--text">
                    <v-icon left color="error">mdi-shield-alert</v-icon>
                    高风险警告
                </v-card-title>
                <v-card-text>
                    <v-alert dense outlined type="error" class="mb-3">
                        {{ fsrsRescheduleRiskMessage }}
                    </v-alert>

                    <div class="body-2 grey--text text--darken-1 mb-3">
                        继续操作将按上述风险执行重排，请确认你已了解复习量变化。
                    </div>

                    <v-alert v-if="fsrsRescheduleCountdown > 0" dense outlined type="info" class="mb-0">
                        请等待 {{ fsrsRescheduleCountdown }} 秒后确认
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn text @click="fsrsRescheduleRiskDialog = false; stopCountdown();">
                        取消
                    </v-btn>
                    <v-btn
                        color="error"
                        :disabled="fsrsRescheduleCountdown > 0 || fsrsRescheduleConfirmLoading"
                        :loading="fsrsRescheduleConfirmLoading"
                        @click="proceedWithHighRisk"
                    >
                        我知道复习量会变多，继续重排
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- D.4-d-c: Undo confirmation dialog -->
        <v-dialog v-model="fsrsRescheduleUndoDialog" max-width="500" persistent>
            <v-card>
                <v-card-title class="headline">撤销上次重排？</v-card-title>
                <v-card-text>
                    <p class="body-1">
                        这会把上次重排影响的卡片恢复到重排前的到期安排。
                        已经在重排后复习过的卡片不会恢复。
                    </p>
                    <p class="body-1">
                        此操作只影响上次重排，不会修改复习次数、复习历史或 FSRS 参数。
                    </p>
                    <div class="caption grey--text">仅 7 天内可撤销。</div>

                    <v-alert v-if="fsrsRescheduleUndoCountdown > 0" dense outlined type="info" class="mb-0 mt-3">
                        请等待 {{ fsrsRescheduleUndoCountdown }} 秒后确认
                    </v-alert>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="closeUndoDialog">取消</v-btn>
                    <v-btn
                        color="warning"
                        :disabled="fsrsRescheduleUndoCountdown > 0 || fsrsRescheduleUndoLoading"
                        :loading="fsrsRescheduleUndoLoading"
                        @click="confirmUndo"
                    >
                        确认撤销{{ fsrsRescheduleUndoCountdown > 0 ? '（' + fsrsRescheduleUndoCountdown + '）' : '' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                saving: false,
                saveStatus: '',
                reviewIntervals: [],
                fsrsDesiredRetention: 0.90,
                fsrsSaving: false,
                fsrsSaveStatus: '',
                fsrsOptimizationLoading: false,
                fsrsOptimizationMessage: '',
                fsrsOptimizationCanOptimize: false,
                fsrsOptimizationPreview: null,
                fsrsOptimizationConfirmLoading: false,
                fsrsOptimizationApplySuccess: false,

                // Parameter source display
                fsrsParameterSource: 'default',
                fsrsParameterSourceLabel: '当前使用默认参数',
                fsrsParameterLastOptimizedAt: null,
                fsrsParameterCount: 19,
                fsrsHasOptimizedParameters: false,
                fsrsParameterWarning: '',
                fsrsRetentionOptions: [
                    { text: '70%', value: 0.70 },
                    { text: '75%', value: 0.75 },
                    { text: '80%', value: 0.80 },
                    { text: '85%', value: 0.85 },
                    { text: '90%', value: 0.90 },
                    { text: '92%', value: 0.92 },
                    { text: '95%', value: 0.95 },
                    { text: '97%', value: 0.97 },
                ],
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
                // D.4-b: 重排预览
                fsrsReschedulePreviewLoading: false,
                fsrsReschedulePreview: null,
                fsrsReschedulePreviewError: '',
                // D.4-c: 重排确认
                fsrsRescheduleConfirmLoading: false,
                fsrsRescheduleConfirmError: '',
                fsrsRescheduleApplySuccess: false,
                fsrsRescheduleConfirmDialog: false,
                fsrsRescheduleRiskDialog: false,
                fsrsRescheduleRiskMessage: '',
                fsrsRescheduleRiskRequired: false,
                fsrsRescheduleCountdown: 0,
                fsrsRescheduleCountdownTimer: null,
                // D.4-d-c: 撤销重排
                fsrsRescheduleUndoLoading: false,
                fsrsRescheduleUndoSuccess: false,
                fsrsRescheduleUndoError: '',
                fsrsRescheduleUndoDialog: false,
                fsrsRescheduleUndoCountdown: 0,
                fsrsRescheduleUndoCountdownTimer: null,
            }
        },
        props: {
            language: String
        },
        mounted() {
            this.loadSettings();
            this.loadFsrsStats();
            this.loadFsrsOptimizationStatus();
        },
        computed: {
            fsrsDesiredRetentionText() {
                const option = this.fsrsRetentionOptions.find(o => o.value === this.fsrsDesiredRetention);
                return option ? option.text : '';
            },
            retentionExplanation() {
                const explanations = {
                    0.70: '复习压力最低，但会更容易忘，适合临时减负。',
                    0.75: '复习量较少，适合想保持轻量学习的人。',
                    0.80: '偏轻松，能减少复习次数，但记忆保持会下降。',
                    0.85: '负担适中偏轻，适合不想被复习压住的人。',
                    0.90: '记忆效果和复习负担比较平衡。',
                    0.92: '记得更稳一些，但每天复习会变多。',
                    0.95: '追求更牢固记忆，复习负担会明显增加。',
                    0.97: '非常高的保持率，复习压力可能很大，请谨慎选择。',
                };
                return explanations[this.fsrsDesiredRetention] || '';
            },
            isRecommended() {
                return this.fsrsDesiredRetention === 0.90;
            },
            retentionBurdenEstimate() {
                const multiplierMap = {
                    0.70: 0.55,
                    0.75: 0.65,
                    0.80: 0.78,
                    0.85: 0.90,
                    0.90: 1.00,
                    0.92: 1.15,
                    0.95: 1.45,
                    0.97: 1.90,
                };
                const multiplier = multiplierMap[this.fsrsDesiredRetention] || 1.00;
                const enabled = Number(this.fsrsStats.enabled || 0);
                const due = Number(this.fsrsStats.due || 0);
                const reviewedToday = Number(this.fsrsStats.reviewed_today || 0);
                const baseline = Math.max(due, reviewedToday, Math.ceil(enabled * 0.03));
                const estimate = Math.ceil(baseline * multiplier);
                const low = Math.max(0, Math.floor(estimate * 0.8));
                const high = Math.max(low, Math.ceil(estimate * 1.25));
                return { low, high };
            },
            burdenEstimateMessage() {
                if (Number(this.fsrsStats.enabled || 0) === 0) {
                    return '现在还没有启用中的词义卡，先不用担心复习负担。';
                }
                const est = this.retentionBurdenEstimate;
                const range = `按当前数据粗略看，每天大约复习 ${est.low}-${est.high} 张。`;
                if (this.fsrsDesiredRetention === 0.90) {
                    return `${range}90% 是比较平衡的默认选择。`;
                } else if (this.fsrsDesiredRetention < 0.90) {
                    return `${range}会轻松一些，但也更容易忘。`;
                }
                return `${range}记得更稳，但复习会更密。`;
            },
            paramComparisonRows() {
                const preview = this.fsrsOptimizationPreview;
                if (!preview || !preview.preview_available) return [];

                const current = preview.current_parameters || [];
                const optimized = preview.optimized_parameters || [];
                const maxLen = Math.max(current.length, optimized.length);

                const rows = [];
                for (let i = 0; i < maxLen; i++) {
                    const cur = i < current.length ? current[i] : null;
                    const opt = i < optimized.length ? optimized[i] : null;
                    const hasBoth = cur !== null && opt !== null;
                    const diff = hasBoth ? opt - cur : null;
                    const changed = hasBoth ? Math.abs(diff) > 0.0001 : false;
                    rows.push({
                        label: '参数 ' + (i + 1),
                        current: cur,
                        optimized: opt,
                        diff: diff,
                        changed: changed,
                        currentText: cur !== null ? cur.toFixed(4) : '—',
                        optimizedText: opt !== null ? opt.toFixed(4) : '—',
                        diffText: diff !== null
                            ? (changed ? (diff >= 0 ? '+' : '') + diff.toFixed(4) : '≈ 无变化')
                            : '—',
                    });
                }
                return rows;
            },
            paramDiffSummary() {
                const rows = this.paramComparisonRows;
                const changed = rows.filter(r => r.changed);
                let maxAbs = 0;
                changed.forEach(r => {
                    const a = Math.abs(r.diff);
                    if (a > maxAbs) maxAbs = a;
                });
                return {
                    changedCount: changed.length,
                    maxDiffText: changed.length > 0 ? maxAbs.toFixed(4) : '—',
                };
            },
            // D.4-b: 重排预览
            hasReschedulePreviewSamples() {
                return this.fsrsReschedulePreview
                    && this.fsrsReschedulePreview.samples
                    && this.fsrsReschedulePreview.samples.length > 0;
            },
        },
        methods: {
            goToManagePage() {
                window.location.href = '/review-cards/manage';
            },
            formatFloat(value) {
                if (value === null || value === undefined) {
                    return '—';
                }
                return Number(value).toFixed(2);
            },
            formatDate(isoString) {
                if (!isoString) {
                    return '—';
                }
                try {
                    const d = new Date(isoString);
                    if (isNaN(d.getTime())) {
                        return '—';
                    }
                    const pad = (n) => String(n).padStart(2, '0');
                    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
                } catch (e) {
                    return '—';
                }
            },
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
            loadFsrsOptimizationStatus() {
                axios.get('/settings/fsrs/optimization-status')
                    .then((response) => {
                        this.fsrsOptimizationCanOptimize = response.data.can_optimize;
                        this.fsrsOptimizationMessage = response.data.message;

                        // Parameter source
                        this.fsrsParameterSource = response.data.parameters_source || 'default';
                        this.fsrsParameterSourceLabel = response.data.parameters_source_label || '当前使用默认参数';
                        this.fsrsParameterLastOptimizedAt = response.data.last_optimized_at || null;
                        this.fsrsParameterCount = response.data.parameters_count ?? 19;
                        this.fsrsHasOptimizedParameters = response.data.has_optimized_parameters || false;
                        this.fsrsParameterWarning = response.data.parameters_warning || '';
                    })
                    .catch(() => {
                        this.fsrsOptimizationCanOptimize = false;
                        this.fsrsOptimizationMessage = '自动优化状态加载失败，请稍后再试。';
                    });
            },
            runFsrsOptimizationPreflight() {
                this.fsrsOptimizationLoading = true;
                this.fsrsOptimizationMessage = '';
                this.fsrsOptimizationPreview = null;
                this.fsrsOptimizationApplySuccess = false;

                axios.post('/settings/fsrs/optimize')
                    .then((response) => {
                        const data = response.data;
                        this.fsrsOptimizationCanOptimize = data.can_optimize;

                        if (data.preview_available) {
                            this.fsrsOptimizationPreview = data;
                        } else {
                            this.fsrsOptimizationMessage = data.message || '复习记录还不够，先继续复习一段时间再来优化。';
                        }
                    })
                    .catch(() => {
                        this.fsrsOptimizationCanOptimize = false;
                        this.fsrsOptimizationPreview = null;
                        this.fsrsOptimizationMessage = '检查失败了，请稍后再试。';
                    })
                    .finally(() => {
                        this.fsrsOptimizationLoading = false;
                    });
            },
            confirmApplyFsrsParameters() {
                const confirmed = window.confirm('确认应用这组优化参数吗？之后新的复习评分会使用它；已有卡片不会自动重排。');
                if (!confirmed) {
                    return;
                }

                this.fsrsOptimizationConfirmLoading = true;
                this.fsrsOptimizationApplySuccess = false;

                axios.post('/settings/fsrs/optimize', { confirm: true })
                    .then((response) => {
                        this.fsrsOptimizationApplySuccess = response.data.message || '优化参数已保存。';
                        this.fsrsOptimizationCanOptimize = response.data.can_optimize;
                        // Re-fetch preview to show updated current parameters
                        this.fsrsOptimizationPreview = null;
                        this.runFsrsOptimizationPreflight();
                        // Refresh parameter source to show "optimized" status
                        this.loadFsrsOptimizationStatus();
                    })
                    .catch((error) => {
                        const message = error.response?.data?.message
                            || '参数保存失败，请稍后再试。';
                        this.fsrsOptimizationApplySuccess = false;
                        this.fsrsOptimizationMessage = message;
                    })
                    .finally(() => {
                        this.fsrsOptimizationConfirmLoading = false;
                    });
            },
            reviewIntervalChanged(value, index) {
                // split value
                let intervals = [1];
                if (value.length) {
                    intervals = value.split(',');
                }

                // parse numbers and restrict undesired values
                for (let intervalIndex = 0; intervalIndex < intervals.length; intervalIndex++) {
                    let parsedInterval = parseInt(intervals[intervalIndex]);
                    intervals[intervalIndex] = isNaN(parsedInterval) ? 1 : parsedInterval;

                    if (intervals[intervalIndex] > 3650) {
                        intervals[intervalIndex] = 3650;
                    }

                    if (intervals[intervalIndex] < 1) {
                        intervals[intervalIndex] = 1;
                    }
                }

                this.reviewIntervals[index].name = (7 - index) + '';
                this.reviewIntervals[index].values = intervals.join(',');

                this.$nextTick(() => {
                    this.$forceUpdate();
                });
            },
            saveSettings() {
                this.saving = true;

                let reviewIntervalsArray = {};
                for (let intervalIndex = 0; intervalIndex < this.reviewIntervals.length; intervalIndex++) {
                    let key = (parseInt(this.reviewIntervals[intervalIndex].name) * -1);
                    reviewIntervalsArray[key] = this.reviewIntervals[intervalIndex].values.split(',');
                    reviewIntervalsArray[key] = reviewIntervalsArray[key].map(Number);
                }

                axios.post('/settings/global/update', {
                    'settings': {
                        'reviewIntervals': reviewIntervalsArray,
                    }
                }).then(() => {
                    this.reviewIntervals = [];
                    this.loadSettings();
                });
            },
            saveFsrsSettings() {
                this.fsrsSaving = true;

                axios.post('/settings/global/update', {
                    'settings': {
                        'fsrsDesiredRetention': this.fsrsDesiredRetention,
                    }
                }).then(() => {
                    this.fsrsSaving = false;
                    this.fsrsSaveStatus = 'FSRS 设置已保存。新的复习评分会使用该目标保持率；已排程卡片不会自动重排。';
                    setTimeout(() => { this.fsrsSaveStatus = ''; }, 5000);
                }).catch(() => {
                    this.fsrsSaving = false;
                    this.fsrsSaveStatus = '保存失败，请重试。';
                });
            },
            // D.4-b: 重排预览
            formatDaysChange(days) {
                if (!days && days !== 0) return '—';
                if (days < 0) return `提前 ${Math.abs(days)} 天`;
                if (days > 0) return `延后 ${days} 天`;
                return '不变';
            },
            previewFsrsRescheduleImpact(options = {}) {
                const preserveSuccess = options.preserveSuccess === true;
                this.fsrsReschedulePreviewLoading = true;
                this.fsrsReschedulePreviewError = '';
                this.fsrsReschedulePreview = null;
                if (!preserveSuccess) {
                    this.fsrsRescheduleApplySuccess = false;
                }
                this.fsrsRescheduleConfirmError = '';

                axios.post('/settings/fsrs/reschedule-preview')
                    .then((response) => {
                        this.fsrsReschedulePreview = response.data;
                    })
                    .catch(() => {
                        this.fsrsReschedulePreviewError = '重排预览加载失败，请稍后再试。';
                    })
                    .finally(() => {
                        this.fsrsReschedulePreviewLoading = false;
                    });
            },
            // D.4-c: 重排确认
            openRescheduleConfirmDialog() {
                if (!this.fsrsReschedulePreview || !this.fsrsReschedulePreview.preview_available) {
                    return;
                }
                this.fsrsRescheduleConfirmError = '';
                this.fsrsRescheduleConfirmDialog = true;
                this.startCountdown(3);
            },
            startCountdown(seconds = 3) {
                this.fsrsRescheduleCountdown = seconds;
                this.fsrsRescheduleCountdownTimer = setInterval(() => {
                    if (this.fsrsRescheduleCountdown > 0) {
                        this.fsrsRescheduleCountdown--;
                    } else {
                        this.stopCountdown();
                    }
                }, 1000);
            },
            stopCountdown() {
                if (this.fsrsRescheduleCountdownTimer) {
                    clearInterval(this.fsrsRescheduleCountdownTimer);
                    this.fsrsRescheduleCountdownTimer = null;
                }
                // Also clean up undo timer
                if (this.fsrsRescheduleUndoCountdownTimer) {
                    clearInterval(this.fsrsRescheduleUndoCountdownTimer);
                    this.fsrsRescheduleUndoCountdownTimer = null;
                }
            },
            confirmReschedule() {
                if (!this.fsrsReschedulePreview) return;
                this.fsrsRescheduleConfirmLoading = true;
                this.fsrsRescheduleConfirmError = '';

                axios.post('/settings/fsrs/reschedule-confirm', {
                    preview_hash: this.fsrsReschedulePreview.preview_hash,
                    confirm: true,
                }).then(() => {
                    // Preflight passed (200), proceed with apply
                    this.proceedWithApply();
                }).catch((error) => {
                    if (error.response && error.response.status === 409) {
                        this.handleReschedulePreviewExpired();
                    } else if (error.response && error.response.status === 422) {
                        const data = error.response.data;
                        if (data && data.risk_level === 'high' && data.requires_risk_confirm) {
                            // Risk detected in preflight, open risk dialog directly
                            this.fsrsRescheduleConfirmDialog = false;
                            this.fsrsRescheduleRiskMessage = data.message || '重排将导致复习量显著增加。';
                            this.fsrsRescheduleRiskRequired = true;
                            this.fsrsRescheduleRiskDialog = true;
                            this.startCountdown(3);
                        } else if (data && data.risk_level === 'blocked') {
                            this.fsrsRescheduleConfirmError = data.message || '风险过高，无法重排。';
                            this.fsrsRescheduleConfirmDialog = false;
                        } else {
                            this.fsrsRescheduleConfirmError = data && data.message ? data.message : '重排检查未通过。';
                            this.fsrsRescheduleConfirmDialog = false;
                        }
                    } else {
                        this.confirmRescheduleError(error);
                    }
                    this.fsrsRescheduleConfirmLoading = false;
                });
            },
            proceedWithApply() {
                this.fsrsRescheduleConfirmLoading = true;

                axios.post('/settings/fsrs/reschedule-confirm', {
                    preview_hash: this.fsrsReschedulePreview.preview_hash,
                    confirm: true,
                    apply: true,
                }).then((response) => {
                    this.confirmRescheduleSuccess(response.data);
                }).catch((error) => {
                    if (error.response && error.response.status === 409) {
                        this.handleReschedulePreviewExpired();
                    } else if (error.response && error.response.status === 422) {
                        const data = error.response.data;
                        if (data.risk_level === 'high') {
                            this.fsrsRescheduleConfirmDialog = false;
                            this.fsrsRescheduleRiskMessage = data.message || '重排将导致复习量显著增加。';
                            this.fsrsRescheduleRiskRequired = true;
                            this.fsrsRescheduleRiskDialog = true;
                            this.startCountdown(3);
                        } else if (data.risk_level === 'blocked') {
                            this.fsrsRescheduleConfirmError = data.message || '风险过高，无法重排。';
                            this.fsrsRescheduleConfirmDialog = false;
                        } else {
                            this.fsrsRescheduleConfirmError = data.message || '重排失败。';
                            this.fsrsRescheduleConfirmDialog = false;
                        }
                    } else {
                        this.confirmRescheduleError(error);
                    }
                    this.fsrsRescheduleConfirmLoading = false;
                });
            },
            proceedWithHighRisk() {
                if (!this.fsrsReschedulePreview) return;
                this.fsrsRescheduleConfirmLoading = true;

                axios.post('/settings/fsrs/reschedule-confirm', {
                    preview_hash: this.fsrsReschedulePreview.preview_hash,
                    confirm: true,
                    apply: true,
                    risk_confirm: true,
                }).then((response) => {
                    this.confirmRescheduleSuccess(response.data);
                }).catch((error) => {
                    this.confirmRescheduleError(error);
                    this.fsrsRescheduleConfirmLoading = false;
                });
            },
            confirmRescheduleSuccess(data) {
                this.fsrsRescheduleApplySuccess = data.message || '重排完成。';
                this.fsrsRescheduleConfirmDialog = false;
                this.fsrsRescheduleRiskDialog = false;
                this.fsrsRescheduleConfirmError = '';
                this.fsrsRescheduleConfirmLoading = false;
                this.stopCountdown();
                this.loadFsrsStats();
                this.previewFsrsRescheduleImpact({ preserveSuccess: true });
            },
            handleReschedulePreviewExpired() {
                this.stopCountdown();
                this.fsrsRescheduleConfirmDialog = false;
                this.fsrsRescheduleRiskDialog = false;
                this.fsrsReschedulePreview = null;
                this.fsrsRescheduleConfirmError = '预览已过期，请重新点击"看看重排后卡片到期日会怎么变"。';
                this.fsrsRescheduleConfirmLoading = false;
                this.fsrsRescheduleCountdown = 0;
            },
            confirmRescheduleError(error) {
                this.stopCountdown();
                this.fsrsRescheduleConfirmDialog = false;
                this.fsrsRescheduleRiskDialog = false;
                this.fsrsRescheduleConfirmLoading = false;

                if (error.response) {
                    const status = error.response.status;
                    const data = error.response.data;
                    if (status === 409) {
                        this.handleReschedulePreviewExpired();
                        return;
                    } else if (status === 422) {
                        this.fsrsRescheduleConfirmError = data && data.message ? data.message : '重排检查未通过。';
                    } else {
                        this.fsrsRescheduleConfirmError = '重排失败，请不要重复点击。请重新预览后再试。';
                    }
                } else {
                    this.fsrsRescheduleConfirmError = '网络错误，重排没有确认成功。请重新预览后再试。';
                }
            },
            // D.4-d-c: 撤销重排
            openUndoDialog() {
                this.fsrsRescheduleUndoError = '';
                this.fsrsRescheduleUndoDialog = true;
                this.startUndoCountdown(3);
            },
            startUndoCountdown(seconds = 3) {
                this.fsrsRescheduleUndoCountdown = seconds;
                this.fsrsRescheduleUndoCountdownTimer = setInterval(() => {
                    if (this.fsrsRescheduleUndoCountdown > 0) {
                        this.fsrsRescheduleUndoCountdown--;
                    } else {
                        this.stopUndoCountdown();
                    }
                }, 1000);
            },
            stopUndoCountdown() {
                if (this.fsrsRescheduleUndoCountdownTimer) {
                    clearInterval(this.fsrsRescheduleUndoCountdownTimer);
                    this.fsrsRescheduleUndoCountdownTimer = null;
                }
            },
            closeUndoDialog() {
                this.stopUndoCountdown();
                this.fsrsRescheduleUndoDialog = false;
                this.fsrsRescheduleUndoError = '';
                this.fsrsRescheduleUndoCountdown = 0;
            },
            confirmUndo() {
                this.fsrsRescheduleUndoLoading = true;
                this.fsrsRescheduleUndoError = '';

                axios.post('/settings/fsrs/reschedule-undo', {
                    confirm: true,
                }).then((response) => {
                    this.undoSuccess(response.data);
                }).catch((error) => {
                    this.undoError(error);
                });
            },
            undoSuccess(data) {
                this.fsrsRescheduleUndoSuccess = data.message || '撤销成功。';
                this.fsrsRescheduleUndoDialog = false;
                this.fsrsRescheduleUndoLoading = false;
                this.fsrsRescheduleUndoError = '';
                this.stopUndoCountdown();

                // Refresh stats
                this.loadFsrsStats();

                // Clear preview so user must re-preview
                this.fsrsReschedulePreview = null;
                this.fsrsReschedulePreviewError = '';
                this.fsrsRescheduleApplySuccess = false;
                this.fsrsRescheduleConfirmError = '';
            },
            undoError(error) {
                this.stopUndoCountdown();
                this.fsrsRescheduleUndoLoading = false;

                if (error.response) {
                    const data = error.response.data;
                    this.fsrsRescheduleUndoError = data && data.message ? data.message : '撤销请求失败，请稍后重试。';
                } else {
                    this.fsrsRescheduleUndoError = '撤销请求失败，请稍后重试。';
                }
            },
            loadSettings() {
                axios.post('/settings/global/get', {
                    'settingNames': ['reviewIntervals', 'fsrsDesiredRetention']
                }).then((result) => {
                    Object.keys(result.data.reviewIntervals).forEach((key, index) => {
                        this.reviewIntervals.push({
                            name: (key * -1) + '',
                            values: result.data.reviewIntervals[key].join(',')
                        });
                    });

                    if (result.data.fsrsDesiredRetention !== undefined && result.data.fsrsDesiredRetention !== null) {
                        this.fsrsDesiredRetention = result.data.fsrsDesiredRetention;
                    }

                    this.saving = false;
                    this.$forceUpdate();
                });
            }
        }
    }
</script>
