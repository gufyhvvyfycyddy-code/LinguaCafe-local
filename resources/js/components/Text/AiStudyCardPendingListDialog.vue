<template>
    <v-dialog :value="value" @input="$emit('input', $event)" max-width="640" scrollable>
        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon small class="mr-2">mdi-format-list-bulleted</v-icon>
                待 AI 解释的词
                <v-spacer />
                <v-btn icon small @click="$emit('input', false)"><v-icon>mdi-close</v-icon></v-btn>
            </v-card-title>
            <v-card-text style="max-height: 60vh;">
                <!-- V3: 待解释 / 已取消 / 已处理 切换 -->
                <div class="d-flex align-center mt-2 mb-2">
                    <v-btn-toggle :value="statusFilter" @change="$emit('update:status-filter', $event)" dense mandatory>
                        <v-btn x-small value="pending">
                            待解释 ({{ pendingItems.length }})
                        </v-btn>
                        <v-btn x-small value="dismissed">
                            已取消 ({{ dismissedItems.length }})
                        </v-btn>
                        <v-btn x-small value="processed">
                            已处理 ({{ processedItems.length }})
                        </v-btn>
                    </v-btn-toggle>
                </div>

                <v-alert
                    v-if="error"
                    dense
                    text
                    type="error"
                    class="mt-2"
                >{{ error }}</v-alert>
                <v-alert
                    v-if="message"
                    dense
                    text
                    type="success"
                    class="mt-2"
                >{{ message }}</v-alert>

                <div v-if="loading" class="text-center pa-4">
                    <v-progress-circular indeterminate color="primary" />
                </div>

                <!-- 待解释列表 -->
                <template v-else-if="statusFilter === 'pending'">
                    <div v-if="pendingItems.length === 0" class="text-center text--secondary pa-4">
                        暂无待 AI 解释的词。
                    </div>
                    <v-list v-else dense>
                        <v-list-item v-for="item in pendingItems" :key="item.id" class="px-0">
                            <v-list-item-content>
                                <v-list-item-title class="d-flex align-center">
                                    <span class="font-weight-medium default-font">{{ item.word }}</span>
                                    <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                </v-list-item-title>
                                <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                    {{ item.sentence_text }}
                                </v-list-item-subtitle>
                                <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                    状态：{{ item.status === 'pending' ? '待解释' : item.status }}
                                    <span class="ml-2">| 添加于 {{ formatDate(item.created_at) }}</span>
                                </v-list-item-subtitle>
                            </v-list-item-content>
                            <v-list-item-action>
                                <v-btn
                                    x-small
                                    rounded
                                    depressed
                                    color="error"
                                    :loading="dismissLoadingId === item.id"
                                    @click="$emit('dismiss', item.id)"
                                >
                                    <v-icon x-small class="mr-1">mdi-close</v-icon>
                                    取消
                                </v-btn>
                            </v-list-item-action>
                        </v-list-item>
                    </v-list>
                </template>

                <!-- V3: 已取消列表（含恢复按钮） -->
                <template v-else-if="statusFilter === 'dismissed'">
                    <div v-if="dismissedItems.length === 0" class="text-center text--secondary pa-4">
                        暂无已取消的词。
                    </div>
                    <v-list v-else dense>
                        <v-list-item v-for="item in dismissedItems" :key="item.id" class="px-0">
                            <v-list-item-content>
                                <v-list-item-title class="d-flex align-center">
                                    <span class="font-weight-medium default-font">{{ item.word }}</span>
                                    <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                </v-list-item-title>
                                <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                    {{ item.sentence_text }}
                                </v-list-item-subtitle>
                                <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                    状态：已取消
                                    <span class="ml-2">| 添加于 {{ formatDate(item.created_at) }}</span>
                                </v-list-item-subtitle>
                            </v-list-item-content>
                            <v-list-item-action>
                                <v-btn
                                    x-small
                                    rounded
                                    depressed
                                    color="success"
                                    :loading="restoreLoadingId === item.id"
                                    @click="$emit('restore', item.id)"
                                >
                                    <v-icon x-small class="mr-1">mdi-restore</v-icon>
                                    恢复
                                </v-btn>
                            </v-list-item-action>
                        </v-list-item>
                    </v-list>
                </template>

                <!-- V5-lifecycle: 已处理列表（只读，无操作按钮） -->
                <template v-else-if="statusFilter === 'processed'">
                    <div v-if="processedItems.length === 0" class="text-center text--secondary pa-4">
                        暂无已处理的词。
                    </div>
                    <v-list v-else dense>
                        <v-list-item v-for="item in processedItems" :key="item.id" class="px-0">
                            <v-list-item-content>
                                <v-list-item-title class="d-flex align-center">
                                    <span class="font-weight-medium default-font">{{ item.word }}</span>
                                    <span v-if="item.lemma && item.lemma !== item.word" class="text-caption text--secondary ml-2">({{ item.lemma }})</span>
                                </v-list-item-title>
                                <v-list-item-subtitle v-if="item.sentence_text" class="text-caption text--secondary mt-1" style="white-space: normal; line-height: 1.4;">
                                    {{ item.sentence_text }}
                                </v-list-item-subtitle>
                                <v-list-item-subtitle class="text-caption text--secondary mt-1">
                                    状态：已处理
                                    <span class="ml-2">| 处理于 {{ formatDate(item.updated_at) }}</span>
                                </v-list-item-subtitle>
                            </v-list-item-content>
                        </v-list-item>
                    </v-list>
                </template>
            </v-card-text>
            <v-card-actions class="d-flex flex-column align-stretch pa-3">
                <v-btn
                    block
                    color="primary"
                    :disabled="pendingItems.length === 0"
                    @click="$emit('open-preview')"
                >
                    <v-icon small class="mr-1">mdi-rocket-launch</v-icon>
                    生成 AI 示意卡
                </v-btn>
                <div class="text-caption text--secondary mt-2 text-center">
                    当前共 {{ pendingItems.length }} 个待解释词
                </div>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
/**
 * AiStudyCardPendingListDialog
 * ============================
 * Presentational sub-component for the V3 pending / dismissed list dialog.
 *
 * Design rules:
 *   - Pure presentational (props in, events out).
 *   - Does NOT call axios.
 *   - Does NOT import Vuex / mapState.
 *   - Does NOT know about SideBox / Box / parent internals.
 *   - Only formats dates for display; no other business logic.
 *
 * Events:
 *   - input (boolean) — v-model for dialog visibility
 *   - update:status-filter (string) — pending | dismissed
 *   - dismiss (itemId)
 *   - restore (itemId)
 *   - open-preview ()
 *
 * (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4)
 */
export default {
    name: 'AiStudyCardPendingListDialog',
    props: {
        value: { type: Boolean, default: false },
        pendingItems: { type: Array, default: () => [] },
        dismissedItems: { type: Array, default: () => [] },
        processedItems: { type: Array, default: () => [] },
        statusFilter: { type: String, default: 'pending' },
        loading: { type: Boolean, default: false },
        error: { type: String, default: '' },
        message: { type: String, default: '' },
        dismissLoadingId: { type: [Number, String, null], default: null },
        restoreLoadingId: { type: [Number, String, null], default: null },
    },
    methods: {
        formatDate(value) {
            if (!value) return '';
            const date = new Date(value);
            if (isNaN(date.getTime())) return value;
            const yyyy = date.getFullYear();
            const mm = String(date.getMonth() + 1).padStart(2, '0');
            const dd = String(date.getDate()).padStart(2, '0');
            const hh = String(date.getHours()).padStart(2, '0');
            const mi = String(date.getMinutes()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd} ${hh}:${mi}`;
        },
    },
};
</script>
