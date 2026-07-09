<template>
    <!--
        SenseReviewEditDialog — edit-sense-card dialog.

        Owns the edit form state and the save API call. Emits `saved` with
        the persisted card payload so the parent can update its card list
        without re-fetching. Emits `input` (v-model) to close.

        Contract:
         - Emits 'input' (false) to close the dialog.
         - Emits 'saved' (persisted card payload) on successful save.
         - Does NOT touch ReviewLog, FSRS, or the card queue directly.
         - The parent stays the single owner of the cards array; this
           component only reports the saved result back.
    -->
    <v-dialog :value="value" max-width="600" @input="$emit('input', $event)">
        <v-card>
            <v-card-title>编辑词义卡片</v-card-title>
            <v-card-text>
                <v-row dense>
                    <v-col cols="6">
                        <v-text-field
                            v-model="form.pos"
                            label="词性"
                            dense
                            hide-details="auto"
                            class="mb-3"
                        />
                    </v-col>
                    <v-col cols="6">
                        <v-text-field
                            v-model="form.sense_zh"
                            label="中文释义"
                            dense
                            hide-details="auto"
                            class="mb-3"
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-text-field
                            v-model="form.sense_en"
                            label="英文释义"
                            dense
                            hide-details="auto"
                            class="mb-3"
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-textarea
                            v-model="form.example_sentence_en"
                            label="英文例句"
                            dense
                            hide-details="auto"
                            rows="2"
                            class="mb-3"
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-text-field
                            v-model="form.example_sentence_zh"
                            label="中文例句"
                            dense
                            hide-details="auto"
                            class="mb-3"
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-text-field
                            v-model="form.aliases_zh_text"
                            label="近义译法（逗号分隔）"
                            dense
                            hide-details="auto"
                            class="mb-3"
                        />
                    </v-col>
                    <v-col cols="12">
                        <v-text-field
                            v-model="form.collocations_text"
                            label="搭配（逗号分隔）"
                            dense
                            hide-details="auto"
                            class="mb-3"
                        />
                    </v-col>
                </v-row>
                <v-alert v-if="error" type="error" dense outlined class="mt-2">{{ error }}</v-alert>
            </v-card-text>
            <v-card-actions>
                <v-spacer />
                <v-btn text @click="cancel" :disabled="saving">取消</v-btn>
                <v-btn color="primary" :loading="saving" @click="save">保存</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<script>
    /**
     * Props:
     *  - value (Boolean): v-model controlling dialog visibility.
     *  - card (Object): the current review card to edit. Must contain
     *    review_card_id and the editable fields.
     *
     * When the dialog opens (value flips to true), the form is pre-filled
     * from `card`. The parent does NOT need to pre-fill anything.
     */
    export default {
        name: 'SenseReviewEditDialog',
        props: {
            value: {
                type: Boolean,
                default: false,
            },
            card: {
                type: Object,
                default: null,
            },
        },
        data() {
            return {
                saving: false,
                error: '',
                form: {
                    pos: '',
                    sense_zh: '',
                    sense_en: '',
                    example_sentence_en: '',
                    example_sentence_zh: '',
                    aliases_zh_text: '',
                    collocations_text: '',
                },
            };
        },
        watch: {
            // Pre-fill the form whenever the dialog opens. Using watch
            // (instead of a parent-side startEdit method) keeps the form
            // state local to this component.
            value(open) {
                if (open && this.card) {
                    this.prefillForm();
                }
                if (!open) {
                    this.error = '';
                }
            },
        },
        methods: {
            prefillForm() {
                const c = this.card || {};
                this.form = {
                    pos: c.pos || '',
                    sense_zh: c.sense_zh || '',
                    sense_en: c.sense_en || '',
                    example_sentence_en: c.example_sentence_en || '',
                    example_sentence_zh: c.example_sentence_zh || '',
                    aliases_zh_text: Array.isArray(c.aliases_zh)
                        ? c.aliases_zh.join(', ')
                        : '',
                    collocations_text: Array.isArray(c.collocations)
                        ? c.collocations.join(', ')
                        : '',
                };
                this.error = '';
            },
            cancel() {
                this.$emit('input', false);
                this.error = '';
            },
            save() {
                if (!this.card) {
                    return;
                }
                this.saving = true;
                this.error = '';

                const payload = {
                    pos: this.form.pos,
                    sense_zh: this.form.sense_zh,
                    sense_en: this.form.sense_en,
                    example_sentence_en: this.form.example_sentence_en,
                    example_sentence_zh: this.form.example_sentence_zh,
                    aliases_zh: this.form.aliases_zh_text
                        .split(',')
                        .map(s => s.trim())
                        .filter(s => s !== ''),
                    collocations: this.form.collocations_text
                        .split(',')
                        .map(s => s.trim())
                        .filter(s => s !== ''),
                };

                axios.patch(`/review-cards/manage/${this.card.review_card_id}`, payload)
                    .then((response) => {
                        const saved = response.data;
                        this.$emit('saved', saved);
                        this.$emit('input', false);
                    })
                    .catch((err) => {
                        this.error = err.response?.data?.message || '词义卡片保存失败。';
                    })
                    .finally(() => {
                        this.saving = false;
                    });
            },
        },
    }
</script>
