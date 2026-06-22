<template>
    <div class="word-senses-section mt-3">
        <div class="vocab-box-subheader d-flex mb-2">已保存词义</div>

        <div v-if="loading" class="search-result disabled">
            <div class="search-result-definition rounded pr-2">
                正在查询 <v-progress-circular indeterminate class="ml-1" size="16" width="2" color="primary" />
            </div>
        </div>

        <div v-else-if="error" class="search-result disabled">
            <div class="search-result-definition rounded pr-2">
                词义查询失败
            </div>
        </div>

        <div v-else-if="!senses.length" class="search-result disabled">
            <div class="search-result-definition rounded pr-2">
                暂无已保存词义
            </div>
        </div>

        <div v-else>
            <div
                v-for="sense in senses"
                :key="sense.sense_id"
                class="sense-item rounded mb-2 pa-2"
                :class="{ 'sense-confirmed': sense.status === 'confirmed', 'sense-suggested': sense.status === 'ai_suggested' }"
            >
                <div class="d-flex align-center mb-1">
                    <v-chip x-small class="mr-1" :color="sense.status === 'confirmed' ? 'success' : 'warning'">
                        {{ sense.status === 'confirmed' ? '已确认' : 'AI建议' }}
                    </v-chip>
                    <v-chip v-if="sense.pos" x-small outlined class="mr-1">{{ sense.pos }}</v-chip>
                </div>

                <div v-if="sense.sense_zh" class="sense-zh mb-1">
                    <strong>{{ sense.sense_zh }}</strong>
                </div>
                <div v-if="sense.sense_en" class="sense-en mb-1 text--secondary">
                    {{ sense.sense_en }}
                </div>

                <div v-if="sense.aliases_zh && sense.aliases_zh.length" class="sense-aliases mb-1">
                    <span class="text--secondary">近义：</span>
                    <v-chip v-for="(alias, i) in sense.aliases_zh" :key="i" x-small class="mr-1 mb-1">{{ alias }}</v-chip>
                </div>

                <div v-if="sense.collocations && sense.collocations.length" class="sense-collocations mb-1">
                    <span class="text--secondary">搭配：</span>
                    <v-chip v-for="(col, i) in sense.collocations" :key="i" x-small outlined class="mr-1 mb-1">{{ col }}</v-chip>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        lemma: {
            type: String,
            required: true,
        },
        language: {
            type: String,
            default: 'english',
        },
    },
    watch: {
        lemma: {
            immediate: true,
            handler() {
                this.fetchSenses();
            },
        },
    },
    data() {
        return {
            senses: [],
            loading: false,
            error: false,
        };
    },
    methods: {
        fetchSenses() {
            const lemma = this.$props.lemma;
            if (!lemma || lemma.trim() === '') {
                this.senses = [];
                this.loading = false;
                this.error = false;
                return;
            }

            this.loading = true;
            this.error = false;
            this.senses = [];

            axios.get('/senses/candidates', {
                params: {
                    lemma: lemma.trim(),
                    language: this.$props.language,
                },
            })
                .then((response) => {
                    this.senses = response.data || [];
                    this.loading = false;
                })
                .catch(() => {
                    this.error = true;
                    this.loading = false;
                });
        },
    },
};
</script>
