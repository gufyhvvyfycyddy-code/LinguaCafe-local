<template>
    <v-card outlined class="rounded-lg mt-4" :loading="loading">
        <v-card-title>复习显示顺序</v-card-title>
        <v-card-subtitle>控制新卡、跨日学习卡和复习卡的相对顺序。两个复习入口共享同一组设置。</v-card-subtitle>
        <v-card-text>
            <v-alert v-if="saveStatus" dense outlined type="success" class="mb-3">{{ saveStatus }}</v-alert>
            <v-alert v-if="saveError" dense outlined type="error" class="mb-3">{{ saveError }}</v-alert>
            <v-row dense>
                <v-col v-for="field in fields" :key="field.key" cols="12" md="6">
                    <div class="font-weight-medium mb-1">{{ field.label }}</div>
                    <v-select
                        v-model="queueOrder[field.key]"
                        :items="field.items"
                        item-text="text"
                        item-value="value"
                        outlined
                        dense
                        hide-details
                    />
                    <div class="caption grey--text mt-1">{{ field.help }}</div>
                </v-col>
            </v-row>
            <v-alert dense outlined type="info" class="mt-4 mb-0">
                当日学习卡始终最先显示。相同用户、语言、日期、卡片集合和设置会得到相同顺序。
            </v-alert>
            <v-card-actions class="px-0 pb-0">
                <v-spacer />
                <v-btn rounded depressed color="primary" :loading="saving" :disabled="saving" @click="saveFsrsQueueOrder">
                    保存显示顺序设置
                </v-btn>
            </v-card-actions>
        </v-card-text>
    </v-card>
</template>

<script>
import * as AdminReviewSettingsApi from '../../../services/AdminReviewSettingsApi';

export default {
    data() {
        return {
            loading: false,
            saving: false,
            saveStatus: '',
            saveError: '',
            queueOrder: {
                interday_learning_review_order: 'mix',
                new_review_order: 'mix',
                review_sort_order: 'due_random',
                new_sort_order: 'created_asc',
            },
            fields: [
                {
                    key: 'interday_learning_review_order',
                    label: '跨日学习与复习顺序',
                    help: '当跨日学习卡和复习卡同时到期时，如何排列两者。',
                    items: [
                        { text: '混合显示（Anki 默认）', value: 'mix' },
                        { text: '跨日学习在前', value: 'before' },
                        { text: '复习在前', value: 'after' },
                    ],
                },
                {
                    key: 'new_review_order',
                    label: '新卡与复习顺序',
                    help: '控制新卡和复习卡的相对位置。',
                    items: [
                        { text: '混合显示（Anki 默认）', value: 'mix' },
                        { text: '新卡在前', value: 'before' },
                        { text: '复习在前', value: 'after' },
                    ],
                },
                {
                    key: 'review_sort_order',
                    label: '复习卡排序',
                    help: '控制复习卡内部的排列方式。',
                    items: [
                        { text: '按到期日 + 每日稳定随机（Anki 默认）', value: 'due_random' },
                        { text: '按到期日稳定排序', value: 'due_stable' },
                        { text: '按记忆强度升序（最易忘先复习）', value: 'ascending_retrievability' },
                        { text: '每日稳定随机', value: 'random' },
                    ],
                },
                {
                    key: 'new_sort_order',
                    label: '新卡排序',
                    help: '控制新卡内部的排列方式。',
                    items: [
                        { text: '按创建时间升序（Anki 默认）', value: 'created_asc' },
                        { text: '按创建时间降序', value: 'created_desc' },
                        { text: '每日稳定随机', value: 'random' },
                    ],
                },
            ],
        };
    },
    mounted() {
        this.loadFsrsQueueOrder();
    },
    methods: {
        applyServerData(data) {
            this.queueOrder.interday_learning_review_order = data.interday_learning_review_order ?? 'mix';
            this.queueOrder.new_review_order = data.new_review_order ?? 'mix';
            this.queueOrder.review_sort_order = data.review_sort_order ?? 'due_random';
            this.queueOrder.new_sort_order = data.new_sort_order ?? 'created_asc';
        },
        loadFsrsQueueOrder() {
            this.loading = true;
            AdminReviewSettingsApi.getQueueOrder()
                .then(response => this.applyServerData(response.data))
                .catch(() => {})
                .finally(() => { this.loading = false; });
        },
        saveFsrsQueueOrder() {
            this.saving = true;
            this.saveStatus = '';
            this.saveError = '';
            AdminReviewSettingsApi.updateQueueOrder({ ...this.queueOrder })
                .then(response => {
                    this.applyServerData(response.data);
                    this.saveStatus = '复习显示顺序设置已保存。复习页将按新设置排序。';
                    window.setTimeout(() => { this.saveStatus = ''; }, 5000);
                })
                .catch(error => {
                    const message = error.response?.data?.message || '保存失败，请稍后再试。';
                    const errors = error.response?.data?.errors;
                    this.saveError = errors ? `${message} ${Object.values(errors).join(' ')}` : message;
                })
                .finally(() => { this.saving = false; });
        },
    },
};
</script>
