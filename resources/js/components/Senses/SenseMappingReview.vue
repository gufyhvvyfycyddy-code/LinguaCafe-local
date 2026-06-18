<template>
    <v-container id="sense-mapping-review">
        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex align-center">
                词义确认
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">待确认 {{ summary.pending || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">已绑定 {{ summary.bound || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">已忽略 {{ summary.ignored || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">已拒绝 {{ summary.rejected || 0 }}</v-chip>
            </div>

            <v-row dense>
                <v-col cols="12" md="2">
                    <v-select dense filled rounded hide-details label="状态" :items="statuses" v-model="filters.status" @change="reload"></v-select>
                </v-col>
                <v-col cols="12" md="2">
                    <v-text-field dense filled rounded hide-details label="词元" v-model="filters.lemma" @keyup.enter="reload"></v-text-field>
                </v-col>
                <v-col cols="12" md="2">
                    <v-select dense filled rounded hide-details clearable label="GPT 判断" :items="decisions" v-model="filters.decision" @change="reload"></v-select>
                </v-col>
                <v-col cols="12" md="2">
                    <v-text-field dense filled rounded hide-details label="最低置信度" v-model="filters.confidence_min" @keyup.enter="reload"></v-text-field>
                </v-col>
                <v-col cols="12" md="2">
                    <v-select dense filled rounded hide-details clearable label="自动 FSRS" :items="autoFsrsFilters" v-model="filters.auto_fsrs_allowed" @change="reload"></v-select>
                </v-col>
                <v-col cols="12" md="2" class="d-flex">
                    <v-btn depressed rounded color="primary" class="mr-2" @click="reload"><v-icon left>mdi-refresh</v-icon>刷新</v-btn>
                    <v-btn depressed rounded color="foreground" @click="clearFilters"><v-icon left>mdi-filter-remove</v-icon>清空</v-btn>
                </v-col>
            </v-row>
        </v-card>

        <v-card outlined class="rounded-lg pa-3 mb-3">
            <div class="d-flex align-center flex-wrap">
                <v-checkbox dense hide-details class="mr-4" :input-value="allSelected" @change="toggleSelectPage" label="选择当前页"></v-checkbox>
                <v-chip class="mr-2" color="foreground">已选择 {{ selectedIds.length }}</v-chip>
                <v-btn small depressed color="primary" class="ma-1" :disabled="selectedIds.length === 0" @click="bulkConfirm(false)">批量确认</v-btn>
                <v-btn small depressed color="primary" class="ma-1" :disabled="selectedIds.length === 0" @click="bulkConfirm(true)">批量确认并启用 FSRS</v-btn>
                <v-btn small depressed color="foreground" class="ma-1" :disabled="selectedIds.length === 0" @click="bulkSimple('ignore')">批量忽略</v-btn>
                <v-btn small depressed color="foreground" class="ma-1" :disabled="selectedIds.length === 0" @click="bulkSimple('reject')">批量拒绝</v-btn>
                <v-btn small depressed color="warning" class="ma-1" @click="bulkHighConfidence">批量确认高置信度</v-btn>
            </div>
            <v-alert v-if="bulkSummary" dense outlined type="info" class="mt-3 mb-0">
                已处理 {{ bulkSummary.processed_count }}，跳过 {{ bulkSummary.skipped_count }}，确认 {{ bulkSummary.confirmed_count }}，忽略 {{ bulkSummary.ignored_count }}，拒绝 {{ bulkSummary.rejected_count }}，复习卡 {{ bulkSummary.created_review_cards }}。
            </v-alert>
        </v-card>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>
        <v-alert v-if="!loading && occurrences.length === 0" type="info" dense outlined>没有匹配的词义记录。</v-alert>

        <v-card
            v-for="occurrence in occurrences"
            :key="occurrence.occurrence_id"
            outlined
            class="rounded-lg pa-4 mb-3"
        >
            <div class="d-flex align-center mb-2">
                <v-checkbox dense hide-details class="mr-3" v-model="selected" :value="occurrence.occurrence_id"></v-checkbox>
                <div>
                    <v-chip small class="mr-2">{{ statusLabel(occurrence.status) }}</v-chip>
                    <v-chip small class="mr-2">{{ occurrence.decision }}</v-chip>
                    <v-chip small>{{ confidenceLabel(occurrence.confidence) }}</v-chip>
                </div>
                <v-spacer></v-spacer>
                <v-btn small depressed color="primary" class="mx-1" @click="confirmOccurrence(occurrence)"><v-icon left small>mdi-check</v-icon>确认</v-btn>
                <v-btn small depressed color="foreground" class="mx-1" @click="openBind(occurrence)"><v-icon left small>mdi-link</v-icon>改绑</v-btn>
                <v-btn small depressed color="foreground" class="mx-1" @click="openCreate(occurrence)"><v-icon left small>mdi-plus</v-icon>新建词义</v-btn>
                <v-btn small depressed color="foreground" class="mx-1" @click="rejectOccurrence(occurrence)"><v-icon left small>mdi-close</v-icon>拒绝</v-btn>
                <v-btn small depressed color="foreground" class="ml-1" @click="ignoreOccurrence(occurrence)"><v-icon left small>mdi-eye-off</v-icon>忽略</v-btn>
            </div>

            <v-row dense>
                <v-col cols="12" md="7">
                    <div class="caption text--secondary">句子</div>
                    <div class="default-font mb-1">{{ occurrence.sentence_en }}</div>
                    <div class="text--secondary mb-3">{{ occurrence.sentence_zh }}</div>

                    <div class="caption text--secondary">词项</div>
                    <div class="mb-3">
                        <strong>{{ occurrence.surface }}</strong>
                        <span class="text--secondary"> / {{ occurrence.lemma }} / {{ occurrence.pos || 'no pos' }}</span>
                    </div>

                    <div class="caption text--secondary">判断依据</div>
                    <pre class="sense-json">{{ formatJson(occurrence.evidence || occurrence.raw_payload) }}</pre>
                </v-col>
                <v-col cols="12" md="5">
                    <div class="caption text--secondary">当前词义</div>
                    <v-sheet outlined rounded class="pa-3" v-if="occurrence.sense">
                        <div class="font-weight-medium">{{ occurrence.sense.sense_zh }}</div>
                        <div class="text--secondary">{{ occurrence.sense.sense_en }}</div>
                        <div class="caption mt-2">{{ occurrence.sense.sense_key }}</div>
                        <v-chip small class="mt-2 mr-1">{{ occurrence.sense.status }}</v-chip>
                        <v-chip small class="mt-2" v-if="occurrence.sense.fsrs_state">FSRS {{ occurrence.sense.fsrs_state }}</v-chip>
                    </v-sheet>
                    <v-sheet outlined rounded class="pa-3 text--secondary" v-else>
                        尚未绑定词义。
                    </v-sheet>
                </v-col>
            </v-row>
        </v-card>

        <div class="px-2" v-if="pagination.last_page > 1">
            <v-pagination
                class="my-6"
                v-model="pagination.current_page"
                :length="pagination.last_page"
                :total-visible="10"
                prev-icon="mdi-menu-left"
                next-icon="mdi-menu-right"
                @input="loadOccurrences"
            ></v-pagination>
        </div>

        <v-card outlined class="rounded-lg pa-4 mb-4">
            <div class="subheader mb-3 d-flex align-center">
                疑似重复词义
                <v-spacer></v-spacer>
                <v-text-field dense filled rounded hide-details label="词元" class="mr-2" v-model="duplicateLemma" @keyup.enter="loadDuplicates"></v-text-field>
                <v-btn depressed rounded color="foreground" @click="loadDuplicates"><v-icon left>mdi-magnify</v-icon>查找</v-btn>
            </div>
            <v-alert v-if="duplicateGroups.length === 0" dense outlined type="info">还没有加载疑似重复词义。</v-alert>
            <v-expansion-panels v-else accordion>
                <v-expansion-panel v-for="(group, index) in duplicateGroups" :key="index">
                    <v-expansion-panel-header>{{ group.lemma }} / {{ group.pos || 'no pos' }} / {{ group.senses.length }} senses</v-expansion-panel-header>
                    <v-expansion-panel-content>
                        <v-simple-table dense>
                            <thead>
                                <tr>
                                    <th>词义</th>
                                    <th>近义译法</th>
                                    <th>例句</th>
                                    <th>复习卡</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="sense in group.senses" :key="sense.sense_id">
                                    <td>{{ sense.sense_zh }}</td>
                                    <td>{{ (sense.aliases_zh || []).join(', ') }}</td>
                                    <td>{{ sense.example_sentence_en }}</td>
                                    <td>{{ sense.review_card ? sense.review_card.fsrs_state : 'none' }}</td>
                                </tr>
                            </tbody>
                        </v-simple-table>
                    </v-expansion-panel-content>
                </v-expansion-panel>
            </v-expansion-panels>
        </v-card>

        <v-dialog v-model="bindDialog.active" max-width="780">
            <v-card>
                <v-card-title>改绑到已有词义</v-card-title>
                <v-card-text>
                    <v-alert v-if="candidates.length === 0" type="info" dense outlined>没有找到同词元的候选词义。</v-alert>
                    <v-radio-group v-model="bindDialog.senseId">
                        <v-radio
                            v-for="sense in candidates"
                            :key="sense.sense_id"
                            :value="sense.sense_id"
                        >
                            <template v-slot:label>
                                <div>
                                    <strong>{{ sense.sense_zh }}</strong>
                                    <span class="text--secondary"> {{ sense.sense_en }}</span>
                                    <div class="caption">{{ sense.pos || 'no pos' }} / {{ sense.status }} / {{ sense.sense_key }}</div>
                                </div>
                            </template>
                        </v-radio>
                    </v-radio-group>
                    <v-checkbox v-model="bindDialog.autoFsrsAllowed" label="创建词义复习卡"></v-checkbox>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="bindDialog.active = false">取消</v-btn>
                    <v-btn depressed color="primary" :disabled="!bindDialog.senseId" @click="bindOccurrence">改绑</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="createDialog.active" max-width="720">
            <v-card>
                <v-card-title>新建词义</v-card-title>
                <v-card-text>
                    <v-text-field filled dense label="中文释义" v-model="createDialog.form.sense_zh"></v-text-field>
                    <v-text-field filled dense label="英文释义" v-model="createDialog.form.sense_en"></v-text-field>
                    <v-text-field filled dense label="词性" v-model="createDialog.form.pos"></v-text-field>
                    <v-text-field filled dense label="中文近义译法，用逗号分隔" v-model="createDialog.form.aliases_zh"></v-text-field>
                    <v-text-field filled dense label="搭配，用逗号分隔" v-model="createDialog.form.collocations"></v-text-field>
                    <v-checkbox v-model="createDialog.form.auto_fsrs_allowed" label="创建词义复习卡"></v-checkbox>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="createDialog.active = false">取消</v-btn>
                    <v-btn depressed color="primary" :disabled="!createDialog.form.sense_zh" @click="createSense">新建</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </v-container>
</template>

<script>
    export default {
        data: function() {
            return {
                loading: false,
                error: '',
                occurrences: [],
                candidates: [],
                selected: [],
                bulkSummary: null,
                duplicateLemma: '',
                duplicateGroups: [],
                summary: {},
                statuses: [
                    { text: '待确认', value: 'pending' },
                    { text: '已绑定', value: 'bound' },
                    { text: '已忽略', value: 'ignored' },
                    { text: '已拒绝', value: 'rejected' },
                ],
                decisions: [
                    { text: '匹配已有词义', value: 'match_existing_sense' },
                    { text: '新词义', value: 'new_sense' },
                    { text: '不确定', value: 'uncertain' },
                    { text: '忽略', value: 'ignore' },
                    { text: '短语匹配', value: 'phrase_match' },
                ],
                autoFsrsFilters: [
                    { text: '全部', value: null },
                    { text: '允许', value: true },
                    { text: '不允许', value: false },
                ],
                filters: {
                    status: 'pending',
                    lemma: '',
                    decision: null,
                    confidence_min: '',
                    auto_fsrs_allowed: null,
                    per_page: 20,
                },
                pagination: {
                    current_page: 1,
                    last_page: 1,
                    total: 0,
                },
                bindDialog: {
                    active: false,
                    occurrence: null,
                    senseId: null,
                    autoFsrsAllowed: false,
                },
                createDialog: {
                    active: false,
                    occurrence: null,
                    form: {
                        sense_zh: '',
                        sense_en: '',
                        pos: '',
                        aliases_zh: '',
                        collocations: '',
                        auto_fsrs_allowed: false,
                    },
                },
            }
        },
        mounted() {
            this.loadOccurrences();
        },
        computed: {
            selectedIds() {
                return this.selected;
            },
            allSelected() {
                return this.occurrences.length > 0 && this.occurrences.every((occurrence) => this.selected.includes(occurrence.occurrence_id));
            },
        },
        methods: {
            reload() {
                this.pagination.current_page = 1;
                this.loadOccurrences();
            },
            clearFilters() {
                this.filters.status = 'pending';
                this.filters.lemma = '';
                this.filters.decision = null;
                this.filters.confidence_min = '';
                this.filters.auto_fsrs_allowed = null;
                this.reload();
            },
            loadOccurrences() {
                this.loading = true;
                this.error = '';
                axios.get('/senses/occurrences', {
                    params: {
                        status: this.filters.status,
                        lemma: this.filters.lemma || undefined,
                        decision: this.filters.decision || undefined,
                        confidence_min: this.filters.confidence_min || undefined,
                        auto_fsrs_allowed: this.filters.auto_fsrs_allowed === null ? undefined : this.filters.auto_fsrs_allowed,
                        page: this.pagination.current_page,
                        per_page: this.filters.per_page,
                    }
                }).then((response) => {
                    this.occurrences = response.data.data;
                    this.summary = response.data.summary;
                    this.pagination = response.data.pagination;
                    this.selected = this.selected.filter((id) => this.occurrences.some((occurrence) => occurrence.occurrence_id === id));
                }).catch((error) => {
                    this.error = error.response?.data?.message || '词义记录加载失败。';
                }).finally(() => {
                    this.loading = false;
                });
            },
            confirmOccurrence(occurrence) {
                this.postAction(`/senses/occurrences/${occurrence.occurrence_id}/confirm`);
            },
            rejectOccurrence(occurrence) {
                this.postAction(`/senses/occurrences/${occurrence.occurrence_id}/reject`);
            },
            ignoreOccurrence(occurrence) {
                this.postAction(`/senses/occurrences/${occurrence.occurrence_id}/ignore`);
            },
            openBind(occurrence) {
                this.bindDialog.active = true;
                this.bindDialog.occurrence = occurrence;
                this.bindDialog.senseId = occurrence.sense ? occurrence.sense.sense_id : null;
                this.bindDialog.autoFsrsAllowed = occurrence.auto_fsrs_allowed;
                this.candidates = [];
                axios.get('/senses/candidates', {
                    params: {
                        lemma: occurrence.lemma,
                        pos: occurrence.pos,
                    }
                }).then((response) => {
                    this.candidates = response.data;
                });
            },
            bindOccurrence() {
                axios.post(`/senses/occurrences/${this.bindDialog.occurrence.occurrence_id}/bind`, {
                    sense_id: this.bindDialog.senseId,
                    auto_fsrs_allowed: this.bindDialog.autoFsrsAllowed,
                }).then(() => {
                    this.bindDialog.active = false;
                    this.loadOccurrences();
                }).catch((error) => {
                    this.error = error.response?.data?.message || '改绑失败。';
                });
            },
            openCreate(occurrence) {
                this.createDialog.active = true;
                this.createDialog.occurrence = occurrence;
                this.createDialog.form = {
                    sense_zh: occurrence.raw_payload?.sense_zh || '',
                    sense_en: occurrence.raw_payload?.sense_en || '',
                    pos: occurrence.pos || '',
                    aliases_zh: (occurrence.raw_payload?.aliases_zh || []).join(', '),
                    collocations: (occurrence.raw_payload?.collocations || []).join(', '),
                    auto_fsrs_allowed: occurrence.auto_fsrs_allowed,
                };
            },
            createSense() {
                axios.post(`/senses/occurrences/${this.createDialog.occurrence.occurrence_id}/create-sense`, this.createDialog.form)
                    .then(() => {
                        this.createDialog.active = false;
                        this.loadOccurrences();
                    }).catch((error) => {
                        this.error = error.response?.data?.message || '新建词义失败。';
                    });
            },
            postAction(url) {
                axios.post(url).then(() => {
                    this.loadOccurrences();
                }).catch((error) => {
                    this.error = error.response?.data?.message || '操作失败。';
                });
            },
            confidenceLabel(confidence) {
                return `${Math.round((confidence || 0) * 100)}%`;
            },
            formatJson(value) {
                return JSON.stringify(value || {}, null, 2);
            },
            toggleSelectPage(value) {
                if (value) {
                    this.selected = this.occurrences.map((occurrence) => occurrence.occurrence_id);
                } else {
                    this.selected = [];
                }
            },
            bulkConfirm(enableFsrs) {
                axios.post('/senses/occurrences/bulk-confirm', {
                    occurrence_ids: this.selectedIds,
                    auto_fsrs_allowed: enableFsrs,
                }).then((response) => {
                    this.bulkSummary = response.data;
                    this.selected = [];
                    this.loadOccurrences();
                }).catch((error) => {
                    this.error = error.response?.data?.message || 'Bulk confirm failed.';
                });
            },
            bulkSimple(action) {
                if (!window.confirm(`确认批量${action === 'ignore' ? '忽略' : '拒绝'} ${this.selectedIds.length} 条记录？`)) {
                    return;
                }

                axios.post(`/senses/occurrences/bulk-${action}`, {
                    occurrence_ids: this.selectedIds,
                }).then((response) => {
                    this.bulkSummary = response.data;
                    this.selected = [];
                    this.loadOccurrences();
                }).catch((error) => {
                    this.error = error.response?.data?.message || `批量操作失败。`;
                });
            },
            bulkHighConfidence() {
                if (!window.confirm('确认当前筛选范围内所有高置信度已有词义匹配？')) {
                    return;
                }

                axios.post('/senses/occurrences/bulk-confirm-high-confidence', {
                    confidence_min: this.filters.confidence_min || 0.90,
                    decision: this.filters.decision || 'match_existing_sense',
                    lemma: this.filters.lemma || undefined,
                    only_auto_fsrs_allowed: this.filters.auto_fsrs_allowed === true,
                }).then((response) => {
                    this.bulkSummary = response.data;
                    this.selected = [];
                    this.loadOccurrences();
                }).catch((error) => {
                    this.error = error.response?.data?.message || '高置信度批量确认失败。';
                });
            },
            loadDuplicates() {
                axios.get('/senses/possible-duplicates', {
                    params: {
                        lemma: this.duplicateLemma || this.filters.lemma || undefined,
                    }
                }).then((response) => {
                    this.duplicateGroups = response.data;
                }).catch((error) => {
                    this.error = error.response?.data?.message || '疑似重复词义加载失败。';
                });
            },
            statusLabel(status) {
                const labels = {
                    pending: '待确认',
                    bound: '已绑定',
                    ignored: '已忽略',
                    rejected: '已拒绝',
                };

                return labels[status] || status;
            },
        }
    }
</script>

<style scoped>
    .sense-json {
        max-height: 180px;
        overflow: auto;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 12px;
        padding: 8px;
        border: 1px solid rgba(0, 0, 0, 0.12);
        border-radius: 8px;
    }
</style>
