<template>
    <v-card outlined class="home-daily-check-in rounded-lg pa-4">
        <div class="text-h6 font-weight-bold mb-4">今天</div>

        <v-skeleton-loader
            v-if="state === 'loading'"
            type="list-item-three-line"
        ></v-skeleton-loader>

        <template v-else-if="state === 'success' && summary">
            <v-row class="check-in-metrics">
                <v-col cols="6" md="4">
                    <div class="caption text--secondary">连续学习</div>
                    <div class="text-h5 font-weight-bold">{{ summary.streak_days }} 天</div>
                </v-col>
                <v-col cols="6" md="4">
                    <div class="caption text--secondary">今日阅读</div>
                    <div class="text-h5 font-weight-bold">{{ summary.today.reading_completed_count }} 次</div>
                </v-col>
                <v-col cols="6" md="4">
                    <div class="caption text--secondary">今日复习</div>
                    <div class="text-h5 font-weight-bold">{{ summary.today.reviewed_count }} 次</div>
                </v-col>
            </v-row>

            <div class="d-flex flex-wrap align-center mt-2 check-in-actions">
                <v-chip small>{{ summary.today.checked_in ? '已打卡' : '未打卡' }}</v-chip>
                <v-btn
                    v-if="summary.continue_learning && summary.continue_learning.href"
                    class="continue-learning-button"
                    color="primary"
                    rounded
                    depressed
                    :to="summary.continue_learning.href"
                >
                    继续学习
                </v-btn>
            </div>
        </template>

        <v-alert v-else type="error" outlined class="mb-0">
            今日进度暂时无法加载
            <div class="mt-3">
                <v-btn small outlined @click="load">重新加载</v-btn>
            </div>
        </v-alert>
    </v-card>
</template>

<script>
    export default {
        data: function() {
            return {
                state: 'loading',
                summary: null,
            };
        },
        mounted() {
            this.load();
        },
        methods: {
            load() {
                this.state = 'loading';
                this.summary = null;

                axios.get('/home/study-summary').then((response) => {
                    this.summary = response.data;
                    this.state = 'success';
                }).catch(() => {
                    this.summary = null;
                    this.state = 'error';
                });
            },
        },
    };
</script>

<style scoped>
    .check-in-metrics {
        margin-bottom: 0;
    }

    .continue-learning-button {
        min-width: 132px;
        margin-left: auto;
    }

    @media (max-width: 600px) {
        .continue-learning-button {
            width: 100%;
            margin-top: 12px;
            margin-left: 0;
        }
    }
</style>
