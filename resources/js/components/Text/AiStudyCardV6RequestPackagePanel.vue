<template>
    <div class="mt-5 pa-3 rounded" style="border: 1px solid var(--v-gray2-base);">
        <div class="d-flex align-center mb-2">
            <v-icon x-small class="mr-1">mdi-shield-check-outline</v-icon>
            <span class="text-subtitle-1 font-weight-medium">V6 请求包（不调用 AI）</span>
            <v-spacer />
            <v-chip x-small color="info" text-color="white">provider disabled</v-chip>
        </div>

        <v-alert dense text type="info" class="mb-3">
            先生成 V6 请求包；如需调用 AI，只能点击下方明确的“后端预览”按钮。浏览器只调用本地后端，不会直接访问 provider，不会生成 WordSense / ReviewCard，不会写 ReviewLog，也不会改 FSRS。
        </v-alert>

        <v-btn
            small
            color="info"
            :disabled="selectedItemIds.length === 0 || loading"
            :loading="loading"
            @click="generateRequestPackage"
        >
            <v-icon small class="mr-1">mdi-file-code-outline</v-icon>
            生成 V6 请求包（不调用 AI）
        </v-btn>

        <v-alert v-if="error" dense text type="error" class="mt-3">{{ error }}</v-alert>

        <div v-if="requestPackage" class="mt-3">
            <v-btn
                small
                color="warning"
                :disabled="previewLoading"
                :loading="previewLoading"
                @click="generateProviderPreview"
            >
                <v-icon small class="mr-1">mdi-auto-fix</v-icon>
                调用 V6 AI 推荐（后端预览）
            </v-btn>
            <div class="text-caption mt-2">
                只发送到本地 provider-preview 路由；推荐结果默认不勾选，仍需用户确认后才能进入后续制卡流程。
            </div>
        </div>

        <v-alert v-if="previewError" dense text type="error" class="mt-3">{{ previewError }}</v-alert>

        <AiStudyCardPackagePanel
            v-if="requestPackage"
            title="V6 请求包"
            icon="mdi-file-code-outline"
            copy-button-label="复制 V6 请求包"
            :pkg="requestPackage"
            :copy-message="copyMessage"
            :copied="copied"
            warning-text="这是 V6 请求包，不是 AI 输出，也不会生成学习卡。"
            @copy="copyRequestPackage"
        />

        <v-alert v-if="recommendationPackage" dense text type="success" class="mt-3">
            本次 AI 返回 {{ recommendedItemCount }} 个新推荐，自动丢弃 {{ droppedItemCount }} 个重复或不合格项。推荐默认不勾选，仍需你手动确认。
        </v-alert>

        <v-alert v-if="allRecommendationsDropped" dense text type="info" class="mt-3">
            AI 本次没有找到新的可加入候选词，重复项已自动丢弃。你可以换一组待解释词再试。
        </v-alert>

        <AiStudyCardPackagePanel
            v-if="recommendationPackage"
            title="V6 AI 推荐预览（默认不勾选）"
            icon="mdi-auto-fix"
            copy-button-label="复制 V6 AI 推荐预览"
            :pkg="recommendationPackage"
            :copy-message="previewCopyMessage"
            :copied="previewCopied"
            warning-text="这是 AI 生成的候选建议，默认不勾选；reason 只是参考理由，不会自动写入释义，也不会自动生成学习卡。"
            @copy="copyRecommendationPackage"
        />

        <div v-if="recommendationPackage" class="mt-3">
            <v-btn
                small
                outlined
                color="success"
                @click="$emit('apply-recommendations', recommendationPackage)"
            >
                <v-icon small class="mr-1">mdi-arrow-up-bold-box-outline</v-icon>
                导入到 AI 推荐词列表（默认不勾选）
            </v-btn>
            <div class="text-caption mt-2">
                只把推荐候选填入上方列表；不会自动勾选，不会自动生成最终候选包，也不会生成学习卡。
            </div>
        </div>
    </div>
</template>

<script>
import axios from 'axios';
import AiStudyCardPackagePanel from './AiStudyCardPackagePanel.vue';
import { buildV6ProviderPreview, buildV6RequestPackage } from '../../services/AiStudyCardPendingWorkflowService.js';
import { copyTextToClipboard } from '../../services/AiStudyCardClipboardService.js';

/**
 * V6 UI: request package preview plus explicit backend provider-preview trigger.
 *
 * This component is intentionally isolated from AiStudyCardDesktopWorkflow so
 * the main V1-V5 container does not grow back into a large mixed component.
 * It calls only local LinguaCafe V6 endpoints. The browser never calls an
 * external provider directly.
 */
export default {
    name: 'AiStudyCardV6RequestPackagePanel',
    components: { AiStudyCardPackagePanel },
    props: {
        selectedItemIds: { type: Array, default: () => [] },
    },
    data() {
        return {
            loading: false,
            previewLoading: false,
            error: '',
            previewError: '',
            requestPackage: null,
            recommendationPackage: null,
            copied: false,
            previewCopied: false,
            copyMessage: '',
            previewCopyMessage: '',
        };
    },
    computed: {
        recommendedItemCount() {
            if (!this.recommendationPackage || !Array.isArray(this.recommendationPackage.recommended_items)) {
                return 0;
            }
            return this.recommendationPackage.recommended_items.length;
        },
        droppedItemCount() {
            if (!this.recommendationPackage || !Array.isArray(this.recommendationPackage.dropped_items)) {
                return 0;
            }
            return this.recommendationPackage.dropped_items.length;
        },
        allRecommendationsDropped() {
            return this.recommendationPackage && this.recommendedItemCount === 0 && this.droppedItemCount > 0;
        },
    },
    methods: {
        generateRequestPackage() {
            if (this.selectedItemIds.length === 0) return;
            this.loading = true;
            this.error = '';
            this.requestPackage = null;
            this.recommendationPackage = null;
            this.copied = false;
            this.previewCopied = false;
            this.copyMessage = '';
            this.previewCopyMessage = '';
            buildV6RequestPackage(axios, this.selectedItemIds)
                .then(({ package: pkg }) => { this.requestPackage = pkg; })
                .catch((error) => { this.error = error.message || '生成 V6 请求包失败。'; })
                .finally(() => { this.loading = false; });
        },
        generateProviderPreview() {
            if (!this.requestPackage) return;
            this.previewLoading = true;
            this.previewError = '';
            this.recommendationPackage = null;
            this.previewCopied = false;
            this.previewCopyMessage = '';
            buildV6ProviderPreview(axios, this.requestPackage)
                .then(({ package: pkg }) => { this.recommendationPackage = pkg; })
                .catch((error) => { this.previewError = error.message || '生成 V6 AI 推荐预览失败。'; })
                .finally(() => { this.previewLoading = false; });
        },
        copyRequestPackage() {
            if (!this.requestPackage) return;
            copyTextToClipboard(JSON.stringify(this.requestPackage, null, 2)).then((result) => {
                this.copied = result.ok;
                this.copyMessage = result.message;
            });
        },
        copyRecommendationPackage() {
            if (!this.recommendationPackage) return;
            copyTextToClipboard(JSON.stringify(this.recommendationPackage, null, 2)).then((result) => {
                this.previewCopied = result.ok;
                this.previewCopyMessage = result.message;
            });
        },
    },
};
</script>
