<template>
    <v-card outlined class="rounded-lg pa-5">
        <div class="text-h5 mb-4">本次复习总结</div>

        <v-row dense class="mb-2">
            <v-col cols="6" md="3">
                <v-card outlined class="pa-3 text-center">
                    <div class="text-h4 primary--text">{{ stats.total }}</div>
                    <div class="caption text--secondary">本次复习</div>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card outlined class="pa-3 text-center">
                    <div class="text-h4 error--text">{{ stats.again }}</div>
                    <div class="caption text--secondary">忘了</div>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card outlined class="pa-3 text-center">
                    <div class="text-h4 warning--text">{{ stats.hard }}</div>
                    <div class="caption text--secondary">勉强记得</div>
                </v-card>
            </v-col>
            <v-col cols="6" md="3">
                <v-card outlined class="pa-3 text-center">
                    <div class="text-h4 success--text">{{ stats.good }}</div>
                    <div class="caption text--secondary">记得</div>
                </v-card>
            </v-col>
            <v-col cols="6" md="3" v-if="stats.easy > 0">
                <v-card outlined class="pa-3 text-center">
                    <div class="text-h4 success--text">{{ stats.easy }}</div>
                    <div class="caption text--secondary">很熟</div>
                </v-card>
            </v-col>
        </v-row>

        <div v-if="stats.needsAttention.length" class="mt-4">
            <div class="text-h6 mb-2">需要重点注意</div>
            <v-list outlined dense class="rounded-lg">
                <v-list-item
                    v-for="(item, i) in attentionItems"
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
                            >{{ ratingLabel(item.rating) }}</v-chip>
                            <span v-if="item.trend === 'declining'" class="error--text text-caption">
                                遗忘趋势上升
                            </span>
                        </v-list-item-subtitle>
                    </v-list-item-content>
                </v-list-item>
            </v-list>
        </div>

        <div v-else class="mt-4 body-1 success--text">
            本次没有需要重点注意的词义，做得不错。
        </div>

        <v-divider class="my-4" />

        <div class="d-flex justify-center flex-wrap">
            <v-btn
                v-if="hasMoreCards"
                depressed
                rounded
                color="primary"
                class="ma-2"
                @click="$emit('continue-review')"
            >继续复习</v-btn>
            <v-btn
                depressed
                rounded
                outlined
                class="ma-2"
                @click="$emit('exit-review')"
            >结束并离开</v-btn>
        </div>
    </v-card>
</template>

<script>
    // SenseReviewSessionSummary-1000-1
    //
    // Presentational component for the SenseReview "本次复习总结" feature.
    // Pure render of session stats + needs-attention list + action buttons.
    //
    // Contract:
    //   - Props: stats (from SenseReviewSessionTracker.sessionStats),
    //            hasMoreCards (whether the queue still has cards).
    //   - Events: continue-review, exit-review.
    //   - Does NOT call any backend API.
    //   - Does NOT write ReviewLog or touch FSRS.
    //   - Does NOT handle hotkeys (parent disables them while summary is shown).
    //   - "继续复习" only renders when hasMoreCards is true, so the user is
    //     never shown a misleading button when the queue is empty.
    export default {
        name: 'SenseReviewSessionSummary',
        props: {
            stats: {
                type: Object,
                required: true,
            },
            hasMoreCards: {
                type: Boolean,
                default: false,
            },
        },
        computed: {
            // Limit to a reasonable number to avoid an overly long summary.
            attentionItems() {
                const items = this.stats.needsAttention || [];
                return items.slice(0, 12);
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
        },
    }
</script>
