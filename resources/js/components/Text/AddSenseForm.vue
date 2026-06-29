<template>
    <div class="sense-form rounded pa-3 mt-3">
        <div class="d-flex align-center mb-2">
            <strong>添加新释义</strong>
            <span v-if="prefillSource" class="text-caption text--secondary ml-2">来自{{ prefillSource }}预填</span>
            <v-spacer />
            <v-btn icon small @click="$emit('cancel')"><v-icon small>mdi-close</v-icon></v-btn>
        </div>

        <v-select
            dense
            filled
            rounded
            hide-details
            class="mb-2"
            label="词性"
            :items="posOptions"
            item-text="label"
            item-value="value"
            v-model="localForm.pos"
        />
        <v-textarea
            dense
            filled
            rounded
            hide-details
            no-resize
            class="mb-2"
            height="70"
            label="中文释义"
            placeholder="例如：落下；掉下"
            v-model="localForm.sense_zh"
        />
        <div class="d-flex align-center mb-2">
            <v-btn
                x-small
                text
                color="primary"
                @click="showAdvanced = !showAdvanced"
            >
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
            <v-text-field dense filled rounded hide-details class="mb-2" label="例句（可选）" v-model="localForm.example_sentence_en" />
            <v-text-field dense filled rounded hide-details class="mb-2" label="近义译法，用逗号分隔" v-model="localForm.aliases_zh" />
            <v-text-field dense filled rounded hide-details class="mb-2" label="搭配，用逗号分隔" v-model="localForm.collocations" />
            <v-checkbox
                v-model="localForm.keep_new"
                label="保持新词"
                dense
                hide-details
                class="mb-2"
            />
            <div class="text-caption text--secondary mb-2 ml-1">勾选后保存释义和复习卡，但不把该词标记为已学习。</div>
        </template>
        <div class="d-flex">
            <v-spacer />
            <v-btn small text class="mr-2" @click="$emit('cancel')">取消</v-btn>
            <v-btn small rounded color="success" :loading="saving" @click="submitForm">保存新释义</v-btn>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        value: {
            type: Object,
            default: () => ({}),
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
    },
    data() {
        return {
            showAdvanced: false,
            localForm: this.buildLocalForm(this.value),
        };
    },
    mounted() {
        this.syncFromValue(this.value);
    },
    watch: {
        value: {
            deep: true,
            handler(val) {
                this.syncFromValue(val);
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
        syncFromValue(val) {
            this.localForm = this.buildLocalForm(val);
            // Auto-expand advanced section if prefill has advanced fields
            if (val && (val.example_sentence_en || val.sense_en || val.aliases_zh || val.collocations)) {
                this.showAdvanced = true;
            } else {
                this.showAdvanced = false;
            }
        },
        submitForm() {
            if (!this.localForm.sense_zh.trim()) {
                return;
            }
            this.$emit('submit', { ...this.localForm });
        },
    },
};
</script>
