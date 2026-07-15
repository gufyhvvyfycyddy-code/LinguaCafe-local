<template>
    <div class="sense-form rounded pa-3 mt-3">
        <div class="d-flex align-center mb-2">
            <strong>{{ isCreate ? '添加新释义' : '编辑释义' }}</strong>
            <span v-if="isCreate && prefillSource" class="text-caption text--secondary ml-2">来自{{ prefillSource }}预填</span>
            <v-spacer />
            <v-btn icon small @click="$emit('cancel')"><v-icon small>mdi-close</v-icon></v-btn>
        </div>

        <v-select
            ref="pos"
            dense
            filled
            rounded
            hide-details="auto"
            class="mb-2"
            label="词性"
            :items="posOptions"
            item-text="label"
            item-value="value"
            :error-messages="fieldErrorMessages('pos')"
            v-model="localForm.pos"
            @input="clearFieldError('pos')"
        />
        <v-textarea
            ref="senseZh"
            dense
            filled
            rounded
            hide-details="auto"
            no-resize
            class="mb-2"
            height="70"
            label="中文释义"
            placeholder="例如：落下；掉下"
            :error-messages="fieldErrorMessages('sense_zh')"
            v-model="localForm.sense_zh"
            @input="clearFieldError('sense_zh')"
        />
        <v-alert v-if="generalError" dense text type="error" class="mb-2">{{ generalError }}</v-alert>
        <div class="d-flex align-center mb-2">
            <v-btn x-small text color="primary" @click="showAdvanced = !showAdvanced">
                <v-icon x-small class="mr-1">{{ showAdvanced ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                {{ showAdvanced ? '收起高级选项' : '高级选项' }}
            </v-btn>
            <v-spacer />
        </div>
        <template v-if="showAdvanced">
            <v-textarea
                dense
                filled
                rounded
                hide-details
                no-resize
                class="mb-2"
                height="70"
                label="英文解释（可选）"
                placeholder="例如：to fall"
                v-model="localForm.sense_en"
            />
            <v-text-field
                v-if="isCreate"
                dense
                filled
                rounded
                hide-details
                class="mb-2"
                label="例句（可选）"
                v-model="localForm.example_sentence_en"
            />
            <v-text-field dense filled rounded hide-details class="mb-2" label="近义译法，用逗号分隔" v-model="localForm.aliases_zh" />
            <v-text-field dense filled rounded hide-details class="mb-2" label="搭配，用逗号分隔" v-model="localForm.collocations" />
            <template v-if="isCreate">
                <v-checkbox v-model="localForm.keep_new" label="保持新词" dense hide-details class="mb-2" />
                <div class="text-caption text--secondary mb-2 ml-1">勾选后保存释义和复习卡，但不把该词标记为已学习。</div>
            </template>
        </template>
        <div class="d-flex">
            <v-spacer />
            <v-btn small text class="mr-2" @click="$emit('cancel')">取消</v-btn>
            <v-btn small rounded color="success" :loading="saving" @click="submitForm">
                {{ isCreate ? '保存新释义' : '保存释义' }}
            </v-btn>
        </div>
    </div>
</template>

<script>
import { validateManualSenseForm } from '../../services/ManualWordSenseFormService';

export default {
    props: {
        value: {
            type: Object,
            default: () => ({}),
        },
        mode: {
            type: String,
            required: true,
            validator: value => ['create', 'edit'].includes(value),
        },
        posOptions: {
            type: Array,
            required: true,
        },
        saving: {
            type: Boolean,
            default: false,
        },
        prefillSource: {
            type: String,
            default: '',
        },
        fieldErrors: {
            type: Object,
            default: () => ({}),
        },
        generalError: {
            type: String,
            default: '',
        },
    },
    data() {
        return {
            showAdvanced: this.hasAdvancedFields(this.value),
            localForm: this.buildLocalForm(this.value),
            localFieldErrors: {
                pos: '',
                sense_zh: '',
            },
        };
    },
    computed: {
        isCreate() {
            return this.mode === 'create';
        },
    },
    watch: {
        fieldErrors: {
            deep: true,
            handler(current, previous) {
                const firstNewError = ['pos', 'sense_zh'].find(field => (
                    current && current[field] && (!previous || !previous[field])
                ));
                if (firstNewError) {
                    this.$nextTick(() => this.focusFirstError(current));
                }
            },
        },
    },
    methods: {
        buildLocalForm(source) {
            return {
                pos: (source && source.pos) || 'verb',
                sense_zh: (source && source.sense_zh) || '',
                sense_en: (source && source.sense_en) || '',
                aliases_zh: (source && source.aliases_zh) || '',
                collocations: (source && source.collocations) || '',
                example_sentence_en: (source && source.example_sentence_en) || '',
                keep_new: (source && source.keep_new) || false,
            };
        },
        hasAdvancedFields(source) {
            return Boolean(source && (
                source.sense_en
                || source.aliases_zh
                || source.collocations
                || (this.mode === 'create' && source.example_sentence_en)
            ));
        },
        fieldErrorMessages(field) {
            const error = this.localFieldErrors[field] || this.fieldErrors[field];
            return error ? [error] : [];
        },
        clearFieldError(field) {
            this.$set(this.localFieldErrors, field, '');
            this.$emit('clear-error', field);
        },
        focusFirstError(fieldErrors) {
            const field = ['pos', 'sense_zh'].find(name => fieldErrors && fieldErrors[name]);
            if (!field) return;

            const refName = field === 'sense_zh' ? 'senseZh' : 'pos';
            const control = this.$refs[refName];
            const element = control && (control.$el || control);
            if (element && element.scrollIntoView) {
                element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            if (control && typeof control.focus === 'function') {
                control.focus();
                return;
            }
            const input = element && element.querySelector && element.querySelector('input, textarea');
            if (input) input.focus();
        },
        submitForm() {
            const validation = validateManualSenseForm(this.localForm);
            this.localFieldErrors = { ...validation.fieldErrors };
            if (validation.fieldErrors.pos || validation.fieldErrors.sense_zh) {
                this.$nextTick(() => this.focusFirstError(validation.fieldErrors));
                return;
            }
            this.$emit('submit', { ...this.localForm });
        },
    },
};
</script>
