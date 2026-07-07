<template>
    <div class="mt-5 pa-3 rounded" style="border: 1px solid var(--v-gray2-base);">
        <div class="d-flex align-center mb-2">
            <v-icon x-small class="mr-1">mdi-shield-check-outline</v-icon>
            <span class="text-subtitle-1 font-weight-medium">V6 请求包（不调用 AI）</span>
            <v-spacer />
            <v-chip x-small color="info" text-color="white">provider disabled</v-chip>
        </div>

        <v-alert dense text type="info" class="mb-3">
            这一步只生成 V6 请求包，不会调用真实 AI，不会生成 WordSense / ReviewCard，不会写 ReviewLog，也不会改 FSRS。
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

        <AiStudyCardPackagePanel
            v-if="requestPackage"
            title="V6 请求包"
            icon="mdi-file-code-outline"
            copy-button-label="复制 V6 请求包"
            :pkg="requestPackage"
            :copy-message="copyMessage"
            :copied="copied"
            warning-text="这是 provider-disabled 请求包，不是 AI 输出，也不会生成学习卡。"
            @copy="copyRequestPackage"
        />
    </div>
</template>

<script>
import axios from 'axios';
import AiStudyCardPackagePanel from './AiStudyCardPackagePanel.vue';
import { buildV6RequestPackage } from '../../services/AiStudyCardPendingWorkflowService.js';
import { copyTextToClipboard } from '../../services/AiStudyCardClipboardService.js';

/**
 * V6-1 UI: provider-disabled request package preview.
 *
 * This component is intentionally isolated from AiStudyCardDesktopWorkflow so
 * the main V1-V5 container does not grow back into a large mixed component.
 * It calls only the local LinguaCafe V6 request-package endpoint and never
 * calls an external AI provider.
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
            error: '',
            requestPackage: null,
            copied: false,
            copyMessage: '',
        };
    },
    methods: {
        generateRequestPackage() {
            if (this.selectedItemIds.length === 0) return;
            this.loading = true;
            this.error = '';
            this.requestPackage = null;
            this.copied = false;
            this.copyMessage = '';
            buildV6RequestPackage(axios, this.selectedItemIds)
                .then(({ package: pkg }) => { this.requestPackage = pkg; })
                .catch((error) => { this.error = error.message || '生成 V6 请求包失败。'; })
                .finally(() => { this.loading = false; });
        },
        copyRequestPackage() {
            if (!this.requestPackage) return;
            copyTextToClipboard(JSON.stringify(this.requestPackage, null, 2)).then((result) => {
                this.copied = result.ok;
                this.copyMessage = result.message;
            });
        },
    },
};
</script>
