<template>
    <v-container id="sense-mapping-review">
        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex align-center">
                Sense mapping review
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">Pending {{ summary.pending || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">Bound {{ summary.bound || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">Ignored {{ summary.ignored || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">Rejected {{ summary.rejected || 0 }}</v-chip>
            </div>

            <v-row dense>
                <v-col cols="12" md="3">
                    <v-select dense filled rounded hide-details label="Status" :items="statuses" v-model="filters.status" @change="reload"></v-select>
                </v-col>
                <v-col cols="12" md="3">
                    <v-text-field dense filled rounded hide-details label="Lemma" v-model="filters.lemma" @keyup.enter="reload"></v-text-field>
                </v-col>
                <v-col cols="12" md="3">
                    <v-select dense filled rounded hide-details clearable label="Decision" :items="decisions" v-model="filters.decision" @change="reload"></v-select>
                </v-col>
                <v-col cols="12" md="3" class="d-flex">
                    <v-btn depressed rounded color="primary" class="mr-2" @click="reload"><v-icon left>mdi-refresh</v-icon>Refresh</v-btn>
                    <v-btn depressed rounded color="foreground" @click="clearFilters"><v-icon left>mdi-filter-remove</v-icon>Clear</v-btn>
                </v-col>
            </v-row>
        </v-card>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>
        <v-alert v-if="!loading && occurrences.length === 0" type="info" dense outlined>No matching occurrences.</v-alert>

        <v-card
            v-for="occurrence in occurrences"
            :key="occurrence.occurrence_id"
            outlined
            class="rounded-lg pa-4 mb-3"
        >
            <div class="d-flex align-center mb-2">
                <div>
                    <v-chip small class="mr-2">{{ occurrence.status }}</v-chip>
                    <v-chip small class="mr-2">{{ occurrence.decision }}</v-chip>
                    <v-chip small>{{ confidenceLabel(occurrence.confidence) }}</v-chip>
                </div>
                <v-spacer></v-spacer>
                <v-btn small depressed color="primary" class="mx-1" @click="confirmOccurrence(occurrence)"><v-icon left small>mdi-check</v-icon>Confirm</v-btn>
                <v-btn small depressed color="foreground" class="mx-1" @click="openBind(occurrence)"><v-icon left small>mdi-link</v-icon>Bind</v-btn>
                <v-btn small depressed color="foreground" class="mx-1" @click="openCreate(occurrence)"><v-icon left small>mdi-plus</v-icon>New sense</v-btn>
                <v-btn small depressed color="foreground" class="mx-1" @click="rejectOccurrence(occurrence)"><v-icon left small>mdi-close</v-icon>Reject</v-btn>
                <v-btn small depressed color="foreground" class="ml-1" @click="ignoreOccurrence(occurrence)"><v-icon left small>mdi-eye-off</v-icon>Ignore</v-btn>
            </div>

            <v-row dense>
                <v-col cols="12" md="7">
                    <div class="caption text--secondary">Sentence</div>
                    <div class="default-font mb-1">{{ occurrence.sentence_en }}</div>
                    <div class="text--secondary mb-3">{{ occurrence.sentence_zh }}</div>

                    <div class="caption text--secondary">Token</div>
                    <div class="mb-3">
                        <strong>{{ occurrence.surface }}</strong>
                        <span class="text--secondary"> / {{ occurrence.lemma }} / {{ occurrence.pos || 'no pos' }}</span>
                    </div>

                    <div class="caption text--secondary">Evidence</div>
                    <pre class="sense-json">{{ formatJson(occurrence.evidence || occurrence.raw_payload) }}</pre>
                </v-col>
                <v-col cols="12" md="5">
                    <div class="caption text--secondary">Current sense</div>
                    <v-sheet outlined rounded class="pa-3" v-if="occurrence.sense">
                        <div class="font-weight-medium">{{ occurrence.sense.sense_zh }}</div>
                        <div class="text--secondary">{{ occurrence.sense.sense_en }}</div>
                        <div class="caption mt-2">{{ occurrence.sense.sense_key }}</div>
                        <v-chip small class="mt-2 mr-1">{{ occurrence.sense.status }}</v-chip>
                        <v-chip small class="mt-2" v-if="occurrence.sense.fsrs_state">FSRS {{ occurrence.sense.fsrs_state }}</v-chip>
                    </v-sheet>
                    <v-sheet outlined rounded class="pa-3 text--secondary" v-else>
                        No sense is bound yet.
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

        <v-dialog v-model="bindDialog.active" max-width="780">
            <v-card>
                <v-card-title>Bind to existing sense</v-card-title>
                <v-card-text>
                    <v-alert v-if="candidates.length === 0" type="info" dense outlined>No candidates found for this lemma.</v-alert>
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
                    <v-checkbox v-model="bindDialog.autoFsrsAllowed" label="Create sense review card"></v-checkbox>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="bindDialog.active = false">Cancel</v-btn>
                    <v-btn depressed color="primary" :disabled="!bindDialog.senseId" @click="bindOccurrence">Bind</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <v-dialog v-model="createDialog.active" max-width="720">
            <v-card>
                <v-card-title>Create sense</v-card-title>
                <v-card-text>
                    <v-text-field filled dense label="Chinese sense" v-model="createDialog.form.sense_zh"></v-text-field>
                    <v-text-field filled dense label="English sense" v-model="createDialog.form.sense_en"></v-text-field>
                    <v-text-field filled dense label="Part of speech" v-model="createDialog.form.pos"></v-text-field>
                    <v-text-field filled dense label="Chinese aliases, comma separated" v-model="createDialog.form.aliases_zh"></v-text-field>
                    <v-text-field filled dense label="Collocations, comma separated" v-model="createDialog.form.collocations"></v-text-field>
                    <v-checkbox v-model="createDialog.form.auto_fsrs_allowed" label="Create sense review card"></v-checkbox>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="createDialog.active = false">Cancel</v-btn>
                    <v-btn depressed color="primary" :disabled="!createDialog.form.sense_zh" @click="createSense">Create</v-btn>
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
                summary: {},
                statuses: ['pending', 'bound', 'ignored', 'rejected'],
                decisions: ['match_existing_sense', 'new_sense', 'uncertain', 'ignore', 'phrase_match'],
                filters: {
                    status: 'pending',
                    lemma: '',
                    decision: null,
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
        methods: {
            reload() {
                this.pagination.current_page = 1;
                this.loadOccurrences();
            },
            clearFilters() {
                this.filters.status = 'pending';
                this.filters.lemma = '';
                this.filters.decision = null;
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
                        page: this.pagination.current_page,
                        per_page: this.filters.per_page,
                    }
                }).then((response) => {
                    this.occurrences = response.data.data;
                    this.summary = response.data.summary;
                    this.pagination = response.data.pagination;
                }).catch((error) => {
                    this.error = error.response?.data?.message || 'Failed to load occurrences.';
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
                    this.error = error.response?.data?.message || 'Failed to bind occurrence.';
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
                        this.error = error.response?.data?.message || 'Failed to create sense.';
                    });
            },
            postAction(url) {
                axios.post(url).then(() => {
                    this.loadOccurrences();
                }).catch((error) => {
                    this.error = error.response?.data?.message || 'Action failed.';
                });
            },
            confidenceLabel(confidence) {
                return `${Math.round((confidence || 0) * 100)}%`;
            },
            formatJson(value) {
                return JSON.stringify(value || {}, null, 2);
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
