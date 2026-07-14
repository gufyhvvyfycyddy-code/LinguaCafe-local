<template>
    <v-container id="study-overview" fluid class="overview-shell py-6">
        <div class="d-flex flex-wrap align-center mb-5">
            <div><h1 class="text-h4 font-weight-bold mb-1">学习总览</h1><div class="text--secondary">{{ metaText }}</div></div>
            <v-spacer /><v-btn outlined color="primary" :to="data ? data.deep_link : '/review-cards/manage'"><v-icon left>mdi-table-search</v-icon>查看范围内卡片</v-btn>
        </div>
        <v-card outlined class="rounded-lg pa-4 mb-5"><v-row align="center">
            <v-col cols="12" md="6"><v-select v-model="savedSearchId" :items="savedSearchOptions" item-text="name" item-value="id" label="统计范围" outlined dense hide-details @change="load" /></v-col>
            <v-col cols="12" md="4"><v-btn-toggle v-model="period" mandatory color="primary" @change="load"><v-btn v-for="value in [30,90,365]" :key="value" :value="value">{{ value }} 天</v-btn></v-btn-toggle></v-col>
            <v-col cols="12" md="2" class="text-md-right"><v-btn icon aria-label="刷新学习总览" :loading="loading" @click="load"><v-icon>mdi-refresh</v-icon></v-btn></v-col>
        </v-row></v-card>
        <v-alert v-if="error" type="error" outlined>{{ error }}</v-alert>
        <v-skeleton-loader v-if="loading && !data" type="card@3" />
        <template v-if="data">
            <section-title title="今日负担" hint="到期与临时限额均按学习时区计算" />
            <v-row class="mb-3"><v-col v-for="item in todayCards" :key="item.label" cols="6" sm="4" lg="2"><metric-card :label="item.label" :value="item.value" :tone="item.tone" /></v-col></v-row>
            <v-alert dense text type="info" class="mb-6">永久上限：新卡 {{ data.today.permanent_new_limit }} / 复习 {{ data.today.permanent_review_limit }}；今日增量：新卡 {{ delta('new_limit_delta') }} / 复习 {{ delta('review_limit_delta') }}；有效上限：新卡 {{ data.today.effective_new_limit }} / 复习 {{ data.today.effective_review_limit }}。</v-alert>
            <v-row>
                <v-col cols="12" lg="7"><v-card outlined class="rounded-lg pa-4 fill-height"><section-title title="未来 30 天到期" :hint="'逾期积压 ' + data.today.overdue_backlog + ' 张，不混入未来日期'" compact /><div v-for="day in data.future_due" :key="day.date" class="due-row d-flex align-center"><span class="caption due-date">{{ day.date.slice(5) }}</span><div class="due-track"><div class="due-fill" :style="{width:barWidth(day.count,maxFutureDue)}"></div></div><strong class="caption due-count">{{ day.count }}</strong></div></v-card></v-col>
                <v-col cols="12" lg="5"><distribution-card title="卡片状态" :groups="cardDistribution" /><distribution-card title="当前计划间隔" :groups="intervalDistribution" class="mt-4" /></v-col>
            </v-row>
            <section-title title="记忆质量" hint="可检索率复用正式 FSRS 计算服务" class="mt-7" />
            <v-row><v-col v-for="item in memoryCards" :key="item.label" cols="12" sm="6" lg="3"><metric-card :label="item.label" :value="item.value" /></v-col></v-row>
            <v-row class="mt-3">
                <v-col cols="12" lg="4"><distribution-card title="评分分布" :groups="ratingDistribution" /></v-col>
                <v-col cols="12" lg="4"><v-card outlined class="rounded-lg pa-4 fill-height"><section-title title="复习用时" :hint="'覆盖率 '+data.review_time.coverage_percentage+'%'" compact /><metric-line label="总用时" :value="formatDuration(data.review_time.total_duration_ms)" /><metric-line label="平均 / 中位" :value="formatDuration(data.review_time.average_duration_ms)+' / '+formatDuration(data.review_time.median_duration_ms)" /><metric-line label="有计时 / 无计时" :value="data.review_time.timed_review_count+' / '+data.review_time.untimed_review_count" /><div class="caption text--secondary mt-3">旧日志保持未计时；null 不按 0 参与计算。</div></v-card></v-col>
                <v-col cols="12" lg="4"><v-card outlined class="rounded-lg pa-4 fill-height"><section-title title="真实保持率" :hint="'覆盖率 '+data.true_retention.coverage_percentage+'%'" compact /><metric-line label="总体" :value="retentionText(data.true_retention.overall_retention,data.true_retention.overall_sample_size)" /><metric-line label="年轻卡" :value="retentionText(data.true_retention.young_retention,data.true_retention.young_sample_size)" /><metric-line label="成熟卡（≥21天）" :value="retentionText(data.true_retention.mature_retention,data.true_retention.mature_sample_size)" /><div class="caption text--secondary mt-3">无法可靠重建前一计划间隔：{{ data.true_retention.unavailable_count }} 条；不猜测。</div></v-card></v-col>
            </v-row>
        </template>
    </v-container>
</template>
<script>
const SectionTitle={props:['title','hint','compact'],template:'<div :class="compact?\'mb-3\':\'mb-4\'"><div class="text-h6 font-weight-bold">{{title}}</div><div v-if="hint" class="caption text--secondary">{{hint}}</div></div>'};
const MetricCard={props:['label','value','tone'],template:'<v-card outlined class="rounded-lg pa-4 metric-card" :class="tone"><div class="caption text--secondary">{{label}}</div><div class="text-h5 font-weight-bold mt-1">{{value}}</div></v-card>'};
const MetricLine={props:['label','value'],template:'<div class="d-flex justify-space-between py-2 metric-line"><span class="text--secondary">{{label}}</span><strong>{{value}}</strong></div>'};
const DistributionCard={props:['title','groups'],components:{SectionTitle},template:'<v-card outlined class="rounded-lg pa-4"><section-title :title="title" compact/><div v-for="item in groups" :key="item.label" class="d-flex justify-space-between py-1"><span class="text--secondary">{{item.label}}</span><strong>{{item.value}}</strong></div></v-card>'};
export default{components:{SectionTitle,MetricCard,MetricLine,DistributionCard},data:()=>({loading:false,error:'',data:null,period:30,savedSearchId:null}),computed:{
savedSearchOptions(){return[{id:null,name:'全部已确认词义卡'}].concat(this.data?.saved_searches||[]);},metaText(){return this.data?`${this.data.meta.language} · ${this.data.meta.saved_search?.name||'全部已确认词义卡'} · ${this.data.meta.period} 天 · ${this.data.meta.scope_card_count} 张卡`:'当前语言与范围的只读学习统计';},
todayCards(){const t=this.data.today;return[{label:'当前到期',value:t.due_count,tone:'metric-primary'},{label:'逾期积压',value:t.overdue_backlog},{label:'今日已复习',value:t.reviewed_today_count},{label:'今日首次新卡',value:t.introduced_today_count},{label:'剩余新卡',value:t.remaining_new},{label:'剩余复习',value:t.remaining_review}];},maxFutureDue(){return Math.max(1,...this.data.future_due.map(d=>d.count));},
cardDistribution(){const s=this.data.cards.state_distribution,l=this.data.cards.lifecycle_distribution;return[['New',s.new],['Learning',s.learning],['Review',s.review],['Relearning',s.relearning],['Active',l.active],['Buried',l.buried],['Suspended',l.suspended],['Archived',l.archived]].map(([label,value])=>({label,value}));},intervalDistribution(){const i=this.data.cards.interval_distribution;return[['<1 天',i.lt_1],['1–6 天',i['1_6']],['7–20 天',i['7_20']],['21–89 天',i['21_89']],['90–364 天',i['90_364']],['≥365 天',i.gte_365],['Unavailable',i.unavailable]].map(([label,value])=>({label,value}));},
memoryCards(){const m=this.data.memory;return[{label:'平均稳定度',value:this.num(m.stability.average)},{label:'稳定度中位数',value:this.num(m.stability.median)},{label:'平均难度',value:this.num(m.difficulty.average)},{label:'平均可检索率',value:this.percent(m.retrievability.average)}];},ratingDistribution(){const c=this.data.ratings.counts;return[['Again',c.again],['Hard',c.hard],['Good',c.good],['Easy',c.easy],['总计',this.data.ratings.total]].map(([label,value])=>({label,value}));}},
mounted(){this.load();},methods:{load(){this.loading=true;this.error='';const params={period:this.period};if(this.savedSearchId)params.saved_search_id=this.savedSearchId;axios.get('/study-overview/data',{params}).then(({data})=>{this.data=data;}).catch(e=>{this.error=e.response?.data?.message||'学习总览加载失败。';}).finally(()=>{this.loading=false;});},delta(key){return this.data.today.override?.[key]||0;},barWidth(value,max){return`${Math.max(value?3:0,value*100/max)}%`;},num(value){return value===null?'Unavailable':Number(value).toFixed(2);},percent(value){return value===null?'Unavailable':`${(Number(value)*100).toFixed(1)}%`;},formatDuration(value){if(value===null)return'Unavailable';const seconds=Math.round(Number(value)/1000);return seconds<60?`${seconds} 秒`:`${Math.floor(seconds/60)}分 ${seconds%60}秒`;},retentionText(value,count){return value===null?`Unavailable (${count})`:`${Number(value).toFixed(1)}% (${count})`;}}};
</script>
<style scoped>
.overview-shell{max-width:1440px;overflow-x:hidden}.metric-card{min-height:92px;border-top:3px solid rgba(127,127,127,.25)!important}.metric-primary{border-top-color:var(--v-primary-base)!important}.due-row{min-height:25px;gap:10px}.due-date{width:42px;flex:0 0 42px}.due-count{width:28px;text-align:right}.due-track{height:7px;border-radius:8px;background:rgba(127,127,127,.14);flex:1;overflow:hidden}.due-fill{height:100%;border-radius:inherit;background:var(--v-primary-base)}.metric-line{border-bottom:1px solid rgba(127,127,127,.15)}@media(max-width:960px){.overview-shell{padding-left:12px!important;padding-right:12px!important}}
</style>
