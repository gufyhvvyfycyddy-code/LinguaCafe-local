<template>
    <div class="word-sense-library">
        <div class="mb-5">
            <h1 class="text-h5 font-weight-bold mb-2">生词</h1>
            <div class="body-2 text--secondary">
                这里是你已经保存并确认的词义。同一个词可以有多个词义。
            </div>
            <div v-if="state === 'success'" class="caption text--secondary mt-2">
                共 {{ pagination.total }} 个词义
            </div>
        </div>

        <div class="word-sense-search mb-5">
            <v-text-field
                v-model="queryInput"
                dense
                outlined
                hide-details
                label="搜索生词或释义"
                placeholder="搜索生词或释义"
                @keyup.enter="submitSearch"
            ></v-text-field>
            <v-btn color="primary" depressed @click="submitSearch">搜索</v-btn>
        </div>

        <div v-if="state === 'loading'" class="py-2">
            <v-skeleton-loader type="list-item-three-line"></v-skeleton-loader>
            <div class="body-2 text--secondary mt-3">正在加载生词…</div>
        </div>

        <v-alert v-else-if="state === 'error'" type="error" outlined>
            生词加载失败，请重试。
            <div class="mt-3">
                <v-btn small outlined @click="retry">重试</v-btn>
            </div>
        </v-alert>

        <template v-else>
            <div v-if="pagination.total === 0 && appliedQuery" class="word-sense-state py-6">
                <div class="body-1 mb-3">没有找到匹配的生词。</div>
                <v-btn small outlined @click="clearSearch">清除搜索</v-btn>
            </div>

            <div v-else-if="pagination.total === 0" class="word-sense-state py-6 body-1">
                还没有保存的生词。你在阅读中保存并确认的词义会出现在这里。
            </div>

            <template v-else>
                <v-card
                    v-for="item in items"
                    :key="item.sense_id"
                    outlined
                    class="word-sense-item rounded-lg pa-4 mb-3"
                >
                    <div class="word-sense-heading">
                        <div class="text-h6 font-weight-bold word-sense-text">{{ item.lemma }}</div>
                        <v-chip small class="word-sense-pos">{{ displayPos(item.pos) }}</v-chip>
                    </div>

                    <div class="body-1 mt-3 word-sense-text">{{ item.sense_zh }}</div>
                    <div v-if="item.sense_en" class="body-2 text--secondary mt-2 word-sense-text">
                        {{ item.sense_en }}
                    </div>

                    <div class="mt-3">
                        <v-btn
                            small
                            text
                            color="primary"
                            :aria-expanded="expandedSenseId === item.sense_id ? 'true' : 'false'"
                            @click="toggleDetails(item.sense_id)"
                        >
                            {{ expandedSenseId === item.sense_id ? '收起' : '查看' }}
                        </v-btn>
                    </div>

                    <div
                        v-if="expandedSenseId === item.sense_id"
                        class="word-sense-details mt-3 pa-3"
                    >
                        <div class="word-sense-text"><strong>{{ item.lemma }}</strong></div>
                        <div class="caption text--secondary mt-1">{{ displayPos(item.pos) }}</div>
                        <div class="body-2 mt-2 word-sense-text">{{ item.sense_zh }}</div>
                        <div v-if="item.sense_en" class="body-2 text--secondary mt-2 word-sense-text">
                            {{ item.sense_en }}
                        </div>
                    </div>
                </v-card>

                <div v-if="pagination.last_page > 1" class="word-sense-pagination mt-5">
                    <v-btn
                        small
                        outlined
                        :disabled="pagination.current_page <= 1"
                        @click="changePage(pagination.current_page - 1)"
                    >
                        上一页
                    </v-btn>
                    <span class="caption text--secondary">
                        第 {{ pagination.current_page }} / {{ pagination.last_page }} 页
                    </span>
                    <v-btn
                        small
                        outlined
                        :disabled="pagination.current_page >= pagination.last_page"
                        @click="changePage(pagination.current_page + 1)"
                    >
                        下一页
                    </v-btn>
                </div>
            </template>
        </template>
    </div>
</template>

<script>
    export default {
        data: function() {
            return {
                state: 'loading',
                items: [],
                pagination: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 20,
                    total: 0,
                },
                queryInput: '',
                appliedQuery: '',
                expandedSenseId: null,
                requestSequence: 0,
            };
        },
        mounted() {
            this.loadPage(1);
        },
        methods: {
            loadPage(page) {
                const requestSequence = ++this.requestSequence;
                const params = {
                    page: page,
                    per_page: 20,
                };

                if (this.appliedQuery) {
                    params.q = this.appliedQuery;
                }

                this.pagination.current_page = page;
                this.state = 'loading';

                axios.get('/word-senses/data', { params: params }).then((response) => {
                    if (requestSequence !== this.requestSequence) {
                        return;
                    }

                    this.items = response.data.data;
                    this.pagination = response.data.pagination;
                    this.state = 'success';
                }).catch(() => {
                    if (requestSequence !== this.requestSequence) {
                        return;
                    }

                    this.state = 'error';
                });
            },
            submitSearch() {
                this.appliedQuery = this.queryInput.trim();
                this.expandedSenseId = null;
                this.loadPage(1);
            },
            clearSearch() {
                this.queryInput = '';
                this.appliedQuery = '';
                this.expandedSenseId = null;
                this.loadPage(1);
            },
            retry() {
                this.loadPage(this.pagination.current_page || 1);
            },
            changePage(page) {
                if (page < 1 || page > this.pagination.last_page || page === this.pagination.current_page) {
                    return;
                }

                this.expandedSenseId = null;
                this.loadPage(page);
            },
            toggleDetails(senseId) {
                this.expandedSenseId = this.expandedSenseId === senseId ? null : senseId;
            },
            displayPos(pos) {
                return pos && pos.trim() ? pos : '未标注';
            },
        },
    };
</script>

<style scoped>
    .word-sense-library {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }

    .word-sense-search {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        max-width: 720px;
    }

    .word-sense-search .v-input {
        flex: 1 1 auto;
        width: 100%;
        min-width: 0;
    }

    .word-sense-item,
    .word-sense-details,
    .word-sense-text {
        min-width: 0;
        max-width: 100%;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .word-sense-heading {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        min-width: 0;
    }

    .word-sense-pos {
        flex: 0 0 auto;
    }

    .word-sense-details {
        border: 1px solid rgba(0, 0, 0, 0.12);
        border-radius: 8px;
    }

    .word-sense-pagination {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    @media (max-width: 430px) {
        .word-sense-search {
            flex-direction: column;
        }

        .word-sense-search .v-btn {
            width: 100%;
        }

        .word-sense-item {
            padding: 12px !important;
        }
    }
</style>
