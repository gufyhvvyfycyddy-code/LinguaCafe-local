<template>
    <v-card outlined class="rounded-lg pa-5">
        <div class="d-flex align-center mb-4">
            <div class="text-h5">今日复习总结</div>
            <v-spacer></v-spacer>
            <v-btn icon small @click="$emit('close')">
                <v-icon>mdi-close</v-icon>
            </v-btn>
        </div>

        <div class="caption text--secondary mb-4">
            今天（{{ summary.day }}，{{ summary.timezone }}）
            共复习 {{ summary.total_reviews }} 次，涉及 {{ summary.distinct_senses }} 个词义。
        </div>

        <div v-if="summary.total_reviews === 0" class="body-1 text--secondary text-center py-6">
            今天还没有完成词义卡复习。
        </div>

        <template v-else>
            <v-row dense class="mb-2">
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 text-center">
                        <div class="text-h4 primary--text">{{ summary.total_reviews }}</div>
                        <div class="caption text--secondary">今日复习</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 text-center">
                        <div class="text-h4 error--text">{{ summary.distribution.again }}</div>
                        <div class="caption text--secondary">忘了</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 text-center">
                        <div class="text-h4 warning--text">{{ summary.distribution.hard }}</div>
                        <div class="caption text--secondary">勉强记得</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 text-center">
                        <div class="text-h4 success--text">{{ summary.distribution.good }}</div>
                        <div class="caption text--secondary">记得</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="3" v-if="summary.distribution.easy > 0">
                    <v-card outlined class="pa-3 text-center">
                        <div class="text-h4 success--text">{{ summary.distribution.easy }}</div>
                        <div class="caption text--secondary">很熟</div>
                    </v-card>
                </v-col>
            </v-row>

            <div class="mt-3 body-2">
                <span class="text--secondary">今日遗忘率：</span>
                <span class="font-weight-medium" :class="forgetRateClass">{{ forgetRateText }}</span>
            </div>

            <div v-if="summary.focus_senses.length" class="mt-4">
                <div class="text-h6 mb-2">今日重点词义</div>
                <v-list outlined dense class="rounded-lg">
                    <v-list-item
                        v-for="(item, i) in summary.focus_senses"
                        :key="i"
                    >
                        <v-list-item-content>
                            <v-list-item-title>
                                <span class="font-weight-medium">{{ item.lemma }}</span>
                                <span class="text--secondary ml-2">{{ item.sense_zh }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <v-chip
                                    x-small
                                    :color="ratingColor(item.last_rating)"
                                    class="mr-2"
                                >{{ ratingLabel(item.last_rating) }}</v-chip>
                                <span class="text-caption text--secondary">
                                    今日 {{ item.total }} 次
                                    <span v-if="item.again > 0" class="error--text">· 忘 {{ item.again }}</span>
                                    <span v-if="item.hard > 0" class="warning--text">· 勉强记得 {{ item.hard }}</span>
                                </span>
                            </v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
            </div>

            <div v-if="summary.recent_reviews.length" class="mt-4">
                <div class="text-h6 mb-2">今日最近复习</div>
                <v-list outlined dense class="rounded-lg">
                    <v-list-item
                        v-for="(item, i) in summary.recent_reviews"
                        :key="i"
                    >
                        <v-list-item-content>
                            <v-list-item-title>
                                <span class="font-weight-medium">{{ item.lemma }}</span>
                                <span class="text--secondary ml-2">{{ item.sense_zh }}</span>
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <v-chip
                                    x-small
                                    :color="ratingColor(item.rating)"
                                    class="mr-2"
                                >{{ item.rating_label }}</v-chip>
                                <span class="text-caption text--secondary">{{ formatTime(item.reviewed_at) }}</span>
                            </v-list-item-subtitle>
                        </v-list-item-content>
                    </v-list-item>
                </v-list>
            </div>

            <v-divider class="my-4" />

            <div class="d-flex justify-center flex-wrap">
                <v-btn
                    depressed
                    rounded
                    outlined
                    class="ma-2"
                    @click="$emit('close')"
                >关闭并继续复习</v-btn>
            </div>
        </template>
    </v-card>
</template>

<script>
    // SenseReviewTodaySummary-1000-1
    //
    // Presentational component for the SenseReview daily cross-session
    // summary. Pure render of backend data returned by the
    // GET /reviews/senses/today-summary endpoint.
    //
    // Distinct from the page-load-scoped session summary: this component
    // shows today's cumulative real ratings across ALL page sessions.
    //
    // Contract:
    //   - Props: summary (object from backend today-summary endpoint).
    //   - Events: close (user wants to dismiss and continue reviewing).
    //   - Does NOT call any backend API itself (parent loads the data).
    //   - Does NOT write any review log or touch scheduling state.
    //   - Does NOT create/destroy cards.
    //   - Does NOT handle hotkeys (parent disables them while shown).
    //   - Empty state shows "今天还没有完成词义卡复习。" with no fake charts.
    export default {
        name: 'SenseReviewTodaySummary',
        props: {
            summary: {
                type: Object,
                required: true,
            },
        },
        computed: {
            forgetRateText() {
                if (this.summary.forget_rate === null || this.summary.forget_rate === undefined) {
                    return '无记录';
                }
                return (this.summary.forget_rate * 100).toFixed(1) + '%';
            },
            forgetRateClass() {
                if (this.summary.forget_rate === null || this.summary.forget_rate === undefined) {
                    return 'text--secondary';
                }
                if (this.summary.forget_rate >= 0.5) {
                    return 'error--text';
                }
                if (this.summary.forget_rate >= 0.25) {
                    return 'warning--text';
                }
                return 'success--text';
            },
        },
        methods: {
            ratingLabel(rating) {
                return {
                    again: '忘了',
                    hard: '勉强记得',
                    good: '记得',
                    easy: '很熟',
                }[rating] || rating;
            },
            ratingColor(rating) {
                return {
                    again: 'error',
                    hard: 'warning',
                    good: 'primary',
                    easy: 'success',
                }[rating] || 'default';
            },
            formatTime(iso) {
                if (!iso) return '';
                try {
                    const d = new Date(iso);
                    return d.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
                } catch (e) {
                    return iso;
                }
            },
        },
    }
</script>
