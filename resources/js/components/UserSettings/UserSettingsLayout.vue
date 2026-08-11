<template>
    <v-container>
        <v-tabs v-model="tab" background-color="white" class="rounded-lg border overflow-hidden">
            <v-tab>账号</v-tab>
            <v-tab>主题</v-tab>
            <v-tab>高级</v-tab>
        </v-tabs>
        <v-tabs-items v-model="tab" id="admin-tab-items" elevation="0" class="no-background rounded-lg mt-4 pa-6">
            <v-tab-item :value="0">
                <user-settings-account :language="$props.language" />
            </v-tab-item>
            <v-tab-item :value="1">
                <user-settings-themes />
            </v-tab-item>
            <v-tab-item :value="2">
                <div class="text-h6 font-weight-bold mb-2">高级功能</div>
                <div class="body-2 text--secondary mb-4">
                    这些工具不会出现在主导航中，需要时可以从这里进入。
                </div>
                <v-list nav class="pa-0">
                    <v-list-item
                        v-for="item in advancedItems"
                        :key="item.url"
                        :to="item.url"
                        class="advanced-feature-link mb-2 rounded-lg"
                    >
                        <v-list-item-icon>
                            <v-icon>{{ item.icon }}</v-icon>
                        </v-list-item-icon>
                        <v-list-item-content>
                            <v-list-item-title>{{ item.title }}</v-list-item-title>
                            <v-list-item-subtitle class="advanced-feature-description">
                                {{ item.description }}
                            </v-list-item-subtitle>
                        </v-list-item-content>
                        <v-list-item-icon>
                            <v-icon>mdi-chevron-right</v-icon>
                        </v-list-item-icon>
                    </v-list-item>
                </v-list>
            </v-tab-item>
        </v-tabs-items>
    </v-container>
</template>

<script>
    export default {
        data: function() {
            return {
                tab: 0,
                advancedItems: [
                    {
                        title: '词汇搜索',
                        description: '搜索阅读中记录的词和短语。',
                        url: '/vocabulary/search',
                        icon: 'mdi-translate',
                    },
                    {
                        title: '自定义学习',
                        description: '按范围创建一次性的复习练习。',
                        url: '/custom-study',
                        icon: 'mdi-tune-variant',
                    },
                    {
                        title: '复习卡管理',
                        description: '查看和调整已保存词义的复习卡。',
                        url: '/review-cards/manage',
                        icon: 'mdi-card-account-details',
                    },
                    {
                        title: '学习总览',
                        description: '查看学习内容和进度概况。',
                        url: '/study-overview',
                        icon: 'mdi-view-dashboard-outline',
                    },
                    {
                        title: '备份与恢复',
                        description: '创建、下载和恢复你的备份。',
                        url: '/admin/dashboard',
                        icon: 'mdi-database',
                    },
                    {
                        title: '文章检查',
                        description: '检查阅读材料中的内容问题。',
                        url: '/article-health',
                        icon: 'mdi-heart-pulse',
                    },
                ],
            }
        },
        props: {
            language: String
        },
        mounted() {
        },
        methods: {
        }
    }
</script>

<style scoped>
    .advanced-feature-link {
        border: 1px solid rgba(0, 0, 0, 0.12);
    }

    .advanced-feature-description {
        white-space: normal;
        overflow-wrap: anywhere;
    }
</style>
