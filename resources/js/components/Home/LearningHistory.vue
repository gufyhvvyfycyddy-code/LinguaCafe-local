<template>
    <v-container id="learning-history" fluid class="history-shell py-6">
        <header class="history-header mb-6">
            <div class="overline primary--text mb-1">LEARNING TIMELINE</div>
            <h1 class="text-h4 font-weight-bold mb-2">学习历史</h1>
            <p class="text--secondary mb-0">新词进入学习与正式复习共用一条时间线；来源证据和当前记忆状态分开呈现。</p>
        </header>

        <v-card outlined class="filter-card rounded-lg pa-4 mb-6">
            <v-row align="end" dense>
                <v-col cols="12" sm="5" md="3">
                    <v-text-field v-model="draftDateFrom" type="date" label="开始日期" outlined dense hide-details />
                </v-col>
                <v-col cols="12" sm="5" md="3">
                    <v-text-field v-model="draftDateTo" type="date" label="结束日期" outlined dense hide-details />
                </v-col>
                <v-col cols="12" sm="2" md="2">
                    <v-btn block color="primary" depressed :loading="loading" @click="applyDates">查询</v-btn>
                </v-col>
                <v-col cols="12" md="4" class="text-md-right">
                    <span v-if="meta.study_timezone" class="caption text--secondary">学习时区：{{ meta.study_timezone }}</span>
                </v-col>
            </v-row>
            <div class="filter-strip mt-4" role="group" aria-label="学习历史筛选">
                <v-btn
                    v-for="option in filterOptions"
                    :key="option.value"
                    small
                    rounded
                    depressed
                    class="mr-2 mb-2"
                    :color="filter === option.value ? 'primary' : 'foreground'"
                    :aria-pressed="filter === option.value"
                    @click="setFilter(option.value)"
                >{{ option.label }}</v-btn>
            </div>
        </v-card>

        <v-alert v-if="error" type="error" outlined class="rounded-lg">{{ error }}</v-alert>
        <v-skeleton-loader v-if="loading && !rows.length" type="article, article, article" />

        <v-card v-else-if="!rows.length && !error" outlined class="empty-state rounded-lg pa-8 text-center">
            <v-icon size="42" color="primary" class="mb-3">mdi-timeline-clock-outline</v-icon>
            <div class="text-h6 font-weight-bold mb-1">这个范围内还没有学习记录</div>
            <div class="text--secondary">调整日期或筛选条件后再试试。</div>
        </v-card>

        <section v-else class="timeline" aria-live="polite">
            <article v-for="row in rows" :key="row.event_key" class="timeline-item">
                <div class="timeline-marker" :class="row.event_type"></div>
                <v-card outlined class="event-card rounded-lg pa-4 mb-4">
                    <div class="d-flex flex-wrap align-start event-heading">
                        <div class="event-copy">
                            <div class="d-flex flex-wrap align-center mb-1">
                                <span class="text-h6 font-weight-bold mr-2">{{ row.lemma || row.surface_form || '未命名词义' }}</span>
                                <v-chip x-small label :color="eventTone(row)" text-color="white">{{ eventLabel(row) }}</v-chip>
                                <v-chip v-if="row.rating" x-small outlined class="ml-2">{{ ratingLabel(row.rating) }}</v-chip>
                            </div>
                            <div class="sense-line">{{ row.sense_zh || row.sense_en || '暂无释义' }}</div>
                            <div v-if="row.pos" class="caption text--secondary mt-1">{{ row.pos }}</div>
                        </div>
                        <v-spacer />
                        <time class="event-time text--secondary">{{ formatTime(row.occurred_at) }}</time>
                    </div>

                    <v-divider class="my-4" />
                    <v-row dense>
                        <v-col cols="12" md="7">
                            <div class="detail-label">来源证据</div>
                            <div class="source-title mt-1">{{ sourceTitle(row) }}</div>
                            <blockquote v-if="row.sentence_en" class="source-sentence mt-2 mb-0">{{ row.sentence_en }}</blockquote>
                            <div v-else class="caption text--secondary mt-2">{{ sourceAccuracyLabel(row.source_accuracy) }}</div>
                        </v-col>
                        <v-col cols="12" md="5" class="current-state">
                            <div class="detail-label">当前记忆状态</div>
                            <div class="state-grid mt-2">
                                <span>生命周期</span><strong>{{ lifecycleLabel(row.current_lifecycle_state) }}</strong>
                                <span>FSRS</span><strong>{{ row.current_fsrs_state || '尚未建卡' }}</strong>
                                <span>复习次数</span><strong>{{ row.current_reps == null ? '—' : row.current_reps }}</strong>
                                <span>下次到期</span><strong>{{ formatDue(row.current_fsrs_due_at) }}</strong>
                            </div>
                            <div class="caption text--secondary mt-2">快照于 {{ formatTime(row.current_state_as_of) }}</div>
                        </v-col>
                    </v-row>
                </v-card>
            </article>
        </section>

        <div v-if="pagination.last_page > 1" class="d-flex justify-center mt-6">
            <v-pagination v-model="page" :length="pagination.last_page" :total-visible="7" @input="load" />
        </div>
    </v-container>
</template>

<script>
export default {
    data: () => ({
        loading: false,
        error: '',
        rows: [],
        filter: 'all',
        dateFrom: '',
        dateTo: '',
        draftDateFrom: '',
        draftDateTo: '',
        page: 1,
        requestId: 0,
        pagination: { page: 1, per_page: 25, total: 0, last_page: 1 },
        meta: {},
        filterOptions: [
            { value: 'all', label: '全部' },
            { value: 'new_learning', label: '新词进入学习' },
            { value: 'reading_review', label: '阅读中复习' },
            { value: 'formal_review', label: '正式复习' },
        ],
    }),
    mounted() {
        this.dateFrom = this.$route.query.date_from || '';
        this.dateTo = this.$route.query.date_to || '';
        this.draftDateFrom = this.dateFrom;
        this.draftDateTo = this.dateTo;
        this.filter = this.filterOptions.some(option => option.value === this.$route.query.filter)
            ? this.$route.query.filter
            : 'all';
        const routePage = Number(this.$route.query.page || 1);
        this.page = Number.isInteger(routePage) && routePage > 0 ? routePage : 1;
        this.load();
    },
    methods: {
        load() {
            const requestId = ++this.requestId;
            this.loading = true;
            this.error = '';
            const params = { filter: this.filter, page: this.page, per_page: 25 };
            if (this.dateFrom && this.dateTo) {
                params.date_from = this.dateFrom;
                params.date_to = this.dateTo;
            }
            axios.get('/learning-history/data', { params }).then(({ data }) => {
                if (requestId !== this.requestId) return;
                this.rows = data.data;
                this.pagination = data.pagination;
                this.meta = data.meta;
                this.dateFrom = data.meta.date_from;
                this.dateTo = data.meta.date_to;
                this.draftDateFrom = this.dateFrom;
                this.draftDateTo = this.dateTo;
                this.syncRoute();
            }).catch(error => {
                if (requestId !== this.requestId) return;
                this.rows = [];
                this.error = error.response?.data?.message || '学习历史加载失败。';
            }).finally(() => {
                if (requestId === this.requestId) this.loading = false;
            });
        },
        applyDates() {
            if (!this.draftDateFrom || !this.draftDateTo) {
                this.error = '请选择完整的开始日期和结束日期。';
                return;
            }
            if (this.draftDateFrom > this.draftDateTo) {
                this.error = '开始日期不能晚于结束日期。';
                return;
            }
            this.dateFrom = this.draftDateFrom;
            this.dateTo = this.draftDateTo;
            this.page = 1;
            this.load();
        },
        setFilter(filter) {
            if (this.filter === filter) return;
            this.filter = filter;
            this.page = 1;
            this.load();
        },
        syncRoute() {
            const query = { date_from: this.dateFrom, date_to: this.dateTo };
            if (this.filter !== 'all') query.filter = this.filter;
            if (this.page > 1) query.page = String(this.page);
            const current = JSON.stringify(this.$route.query);
            if (current !== JSON.stringify(query)) this.$router.replace({ path: '/learning-history', query });
        },
        eventLabel(row) {
            if (row.event_type === 'learning_entry') return '进入学习';
            return row.event_source === 'reading_passive' || row.event_source === 'reading_explicit' ? '阅读复习' : '正式复习';
        },
        eventTone(row) {
            if (row.event_type === 'learning_entry') return 'primary';
            return row.event_source === 'reading_passive' || row.event_source === 'reading_explicit' ? 'teal' : 'deep-purple';
        },
        ratingLabel(value) {
            return { again: 'Again', hard: 'Hard', good: 'Good', easy: 'Easy' }[value] || value;
        },
        sourceTitle(row) {
            if (row.chapter_title) return `《${row.chapter_title}》`;
            if (row.event_type === 'learning_entry' && row.learning_origin === 'legacy_unknown') return '旧数据：来源未知';
            if (row.event_source === 'sense_review') return '词义复习';
            if (row.event_source === 'special_study') return '专项学习';
            return '没有可靠的原文定位';
        },
        sourceAccuracyLabel(value) {
            return { exact_occurrence: '已精确定位到原文', exact_chapter: '已定位到章节，无法可靠定位句子', unavailable: '没有可验证的来源位置' }[value] || '没有可验证的来源位置';
        },
        lifecycleLabel(value) {
            return { active: '学习中', buried: '已暂缓', suspended: '已暂停', archived: '已归档' }[value] || (value || '无卡片');
        },
        formatTime(value) {
            if (!value) return '—';
            return new Intl.DateTimeFormat('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value));
        },
        formatDue(value) {
            if (!value) return '—';
            return new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(value));
        },
    },
};
</script>

<style scoped>
.history-shell{max-width:1120px;overflow-x:hidden}.history-header{border-left:4px solid var(--v-primary-base);padding-left:18px}.history-header p{max-width:720px}.filter-card{background:linear-gradient(135deg,rgba(127,127,127,.045),transparent)}.filter-strip{white-space:normal}.timeline{position:relative;padding-left:32px}.timeline:before{content:"";position:absolute;left:9px;top:10px;bottom:10px;width:2px;background:rgba(127,127,127,.2)}.timeline-item{position:relative}.timeline-marker{position:absolute;left:-30px;top:24px;width:16px;height:16px;border:3px solid var(--v-background-base,#fff);border-radius:50%;background:var(--v-primary-base);box-shadow:0 0 0 2px var(--v-primary-base)}.timeline-marker.review{background:#673ab7;box-shadow:0 0 0 2px #673ab7}.event-card{border-left-width:3px!important;transition:border-color .2s ease}.event-card:hover{border-left-color:var(--v-primary-base)!important}.event-copy{min-width:0}.sense-line{font-size:1rem;line-height:1.55}.event-time{font-size:.82rem;white-space:nowrap}.detail-label{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;font-weight:700;color:var(--v-primary-base)}.source-title{font-weight:600}.source-sentence{border-left:3px solid rgba(127,127,127,.25);padding:8px 12px;color:rgba(0,0,0,.68);font-style:normal}.event-card.theme--dark .source-sentence{color:rgba(255,255,255,.72)}.current-state{border-left:1px solid rgba(127,127,127,.18);padding-left:20px!important}.state-grid{display:grid;grid-template-columns:minmax(80px,1fr) auto;gap:6px 16px;font-size:.86rem}.state-grid span{color:rgba(127,127,127,.95)}.state-grid strong{text-align:right}.empty-state{border-style:dashed!important}@media(max-width:959px){.history-shell{padding-left:12px!important;padding-right:12px!important}.current-state{border-left:0;border-top:1px solid rgba(127,127,127,.18);padding-left:4px!important;padding-top:16px!important;margin-top:8px}}@media(max-width:430px){.history-shell{padding-left:8px!important;padding-right:8px!important}.history-header{padding-left:12px}.history-header h1{font-size:1.75rem!important}.filter-card{padding:12px!important}.timeline{padding-left:24px}.timeline-marker{left:-22px;width:13px;height:13px}.timeline:before{left:8px}.event-card{padding:14px!important}.event-heading{display:block!important}.event-time{display:block;margin-top:8px}.filter-strip .v-btn{margin-right:4px!important}.source-sentence{padding-left:9px}}
</style>
