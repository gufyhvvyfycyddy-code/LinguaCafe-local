<template>
    <v-container class="article-health-page py-6" fluid>
        <v-row no-gutters justify="center">
            <v-col cols="12" lg="10" xl="8">
                <div class="d-flex flex-wrap align-center mb-5">
                    <div>
                        <h1 class="text-h4 font-weight-bold mb-1">内容健康</h1>
                        <p class="text-body-2 text--secondary mb-0">
                            只读检查{{ report && report.scope.book_name ? `“${report.scope.book_name}”` : '当前英语学习资料' }}、来源引用与 tokenizer 就绪状态。
                        </p>
                    </div>
                    <v-spacer />
                    <v-btn
                        color="primary"
                        depressed
                        :loading="loading"
                        @click="loadHealth"
                    >
                        <v-icon left>mdi-refresh</v-icon>
                        重新检查
                    </v-btn>
                </div>

                <v-alert
                    v-if="error"
                    type="error"
                    prominent
                    border="left"
                    class="mb-5"
                >
                    <div class="font-weight-medium">健康报告加载失败</div>
                    <div>{{ error }}</div>
                </v-alert>

                <v-skeleton-loader
                    v-if="loading && !report"
                    type="article, list-item-three-line@3"
                />

                <template v-else-if="report">
                    <v-card outlined class="mb-5 overflow-hidden">
                        <v-card-text class="pa-5">
                            <div class="d-flex flex-wrap align-center">
                                <div>
                                    <div class="text-overline">当前范围</div>
                                    <div class="text-h6">
                                        {{ report.scope.book_name || (report.scope.language === 'english' ? '英语' : report.scope.language) }}
                                    </div>
                                </div>
                                <v-spacer />
                                <v-chip
                                    large
                                    :color="statusColor(report.status)"
                                    text-color="white"
                                >
                                    <v-icon left>{{ statusIcon(report.status) }}</v-icon>
                                    {{ statusLabel(report.status) }}
                                </v-chip>
                            </div>

                            <v-row class="mt-3">
                                <v-col
                                    v-for="metric in summaryMetrics"
                                    :key="metric.label"
                                    cols="6"
                                    sm="3"
                                >
                                    <div class="text-caption text--secondary">{{ metric.label }}</div>
                                    <div class="text-h5 font-weight-bold">{{ metric.value }}</div>
                                </v-col>
                            </v-row>
                        </v-card-text>
                    </v-card>

                    <v-card outlined class="mb-5">
                        <v-card-title class="text-h6">运行条件</v-card-title>
                        <v-card-text>
                            <div
                                v-for="(check, name) in report.checks"
                                :key="name"
                                class="d-flex align-center py-2"
                            >
                                <span>{{ checkLabel(name) }}</span>
                                <v-spacer />
                                <v-chip
                                    small
                                    :color="checkColor(check.status)"
                                    text-color="white"
                                >
                                    {{ checkStatusLabel(check.status) }}
                                </v-chip>
                            </div>
                        </v-card-text>
                    </v-card>

                    <v-alert
                        v-if="report.findings.length === 0"
                        type="success"
                        border="left"
                        prominent
                    >
                        当前范围没有发现文章、来源或词汇健康问题。
                    </v-alert>

                    <v-card v-else outlined>
                        <v-card-title class="text-h6">
                            检查结果
                            <v-spacer />
                            <span class="text-caption text--secondary">
                                {{ report.findings.length }} 项
                            </span>
                        </v-card-title>
                        <v-divider />
                        <v-list three-line>
                            <template v-for="(finding, index) in report.findings">
                                <v-list-item :key="findingKey(finding, index)">
                                    <v-list-item-icon>
                                        <v-avatar
                                            size="36"
                                            :color="severityColor(finding.severity)"
                                        >
                                            <v-icon dark small>
                                                {{ severityIcon(finding.severity) }}
                                            </v-icon>
                                        </v-avatar>
                                    </v-list-item-icon>
                                    <v-list-item-content>
                                        <v-list-item-title class="font-weight-medium">
                                            {{ finding.message }}
                                        </v-list-item-title>
                                        <v-list-item-subtitle>
                                            <code>{{ finding.code }}</code>
                                            <span v-if="finding.entity_id">
                                                · {{ entityLabel(finding.entity_type) }} #{{ finding.entity_id }}
                                            </span>
                                            <span v-if="finding.count > 1">
                                                · {{ finding.count }} 条
                                            </span>
                                        </v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                                <v-divider
                                    v-if="index < report.findings.length - 1"
                                    :key="`${findingKey(finding, index)}-divider`"
                                    inset
                                />
                            </template>
                        </v-list>
                    </v-card>

                    <p class="text-caption text--secondary mt-4 mb-0">
                        本页不会修改文章、词义、复习卡或学习进度。
                        <span v-if="report.scan.truncated">
                            本次报告已达到 {{ report.scan.limit }} 条扫描上限。
                        </span>
                    </p>
                </template>
            </v-col>
        </v-row>
    </v-container>
</template>

<script>
export default {
    name: 'ArticleHealth',
    data() {
        return {
            loading: false,
            error: '',
            report: null,
        }
    },
    computed: {
        summaryMetrics() {
            if (!this.report) {
                return []
            }

            return [
                { label: '全部发现', value: this.report.summary.total },
                { label: '严重', value: this.report.summary.critical },
                { label: '警告', value: this.report.summary.warning },
                { label: '信息', value: this.report.summary.info },
            ]
        },
    },
    mounted() {
        this.loadHealth()
    },
    methods: {
        loadHealth() {
            this.loading = true
            this.error = ''

            const requestedBookId = this.$route?.query?.book_id
            const params = requestedBookId === undefined ? {} : { book_id: requestedBookId }
            axios.get('/article-health/data', { params })
                .then((response) => {
                    this.report = response.data.article_health
                })
                .catch((error) => {
                    this.error = error.response?.data?.message
                        || '无法读取内容健康报告，请稍后重试。'
                })
                .finally(() => {
                    this.loading = false
                })
        },
        statusColor(status) {
            return status === 'critical' ? 'error' : (status === 'warning' ? 'warning' : 'success')
        },
        statusIcon(status) {
            return status === 'healthy' ? 'mdi-check-circle' : 'mdi-alert-circle'
        },
        statusLabel(status) {
            return status === 'healthy' ? '健康' : (status === 'critical' ? '需要立即关注' : '发现需要关注的项目')
        },
        checkColor(status) {
            return status === 'available' ? 'success' : (status === 'unavailable' ? 'warning' : 'grey')
        },
        checkStatusLabel(status) {
            return status === 'available' ? '可用' : (status === 'unavailable' ? '不可用' : '未配置')
        },
        checkLabel(name) {
            return {
                tokenizer: '英文 tokenizer',
                chapter_positions: '章节位置检查',
            }[name] || name
        },
        severityColor(severity) {
            return severity === 'critical' ? 'error' : (severity === 'warning' ? 'warning' : 'info')
        },
        severityIcon(severity) {
            return severity === 'info' ? 'mdi-information' : 'mdi-alert'
        },
        entityLabel(type) {
            return {
                book: '阅读材料',
                chapter: '章节',
                word_sense: '词义',
                word_sense_occurrence: '发生记录',
                encountered_word: '词汇',
            }[type] || '记录'
        },
        findingKey(finding, index) {
            return `${finding.code}-${finding.entity_type || 'scope'}-${finding.entity_id || index}`
        },
    },
}
</script>

<style scoped>
.article-health-page {
    max-width: 1440px;
}

code {
    color: inherit;
    font-size: 0.78rem;
}
</style>
