<template>
    <v-container id="sense-review">
        <v-card outlined class="rounded-lg px-4 pb-4 my-4" :loading="loading">
            <div class="subheader my-4 d-flex align-center">
                Sense FSRS Review
                <v-spacer></v-spacer>
                <v-chip class="mx-1" color="foreground">Due {{ summary.due_count || 0 }}</v-chip>
                <v-chip class="mx-1" color="foreground">Reviewed {{ reviewedCount }}</v-chip>
                <v-chip class="mx-1" color="foreground">Remaining {{ remainingCount }}</v-chip>
            </div>
        </v-card>

        <v-alert v-if="error" type="error" dense outlined>{{ error }}</v-alert>

        <v-card v-if="currentCard" outlined class="rounded-lg pa-5">
            <div class="d-flex align-center mb-3">
                <div>
                    <div class="text-h5 default-font">{{ currentCard.lemma }}</div>
                    <div class="text--secondary">
                        {{ currentCard.surface_form || currentCard.lemma }}
                        <span v-if="currentCard.pos"> / {{ currentCard.pos }}</span>
                    </div>
                </div>
                <v-spacer></v-spacer>
                <v-chip class="mr-1">{{ currentCard.fsrs_state }}</v-chip>
                <v-chip>{{ currentCard.fsrs_reps }} reps</v-chip>
            </div>

            <v-row dense>
                <v-col cols="12" md="6">
                    <div class="caption text--secondary">Chinese sense</div>
                    <div class="sense-main mb-4">{{ currentCard.sense_zh }}</div>

                    <div class="caption text--secondary">English sense</div>
                    <div class="mb-4">{{ currentCard.sense_en || 'No English gloss.' }}</div>

                    <div class="caption text--secondary">Aliases</div>
                    <div class="mb-4">
                        <v-chip small class="mr-1 mb-1" v-for="alias in currentCard.aliases_zh" :key="alias">{{ alias }}</v-chip>
                        <span v-if="!currentCard.aliases_zh.length" class="text--secondary">None</span>
                    </div>

                    <div class="caption text--secondary">Collocations</div>
                    <div>
                        <v-chip small class="mr-1 mb-1" v-for="collocation in currentCard.collocations" :key="collocation">{{ collocation }}</v-chip>
                        <span v-if="!currentCard.collocations.length" class="text--secondary">None</span>
                    </div>
                </v-col>
                <v-col cols="12" md="6">
                    <div class="caption text--secondary">Example</div>
                    <v-sheet outlined rounded class="pa-3 mb-4">
                        <div class="default-font">{{ currentCard.example_sentence_en || 'No example sentence.' }}</div>
                        <div class="text--secondary mt-2">{{ currentCard.example_sentence_zh }}</div>
                    </v-sheet>

                    <div class="caption text--secondary">FSRS</div>
                    <v-simple-table dense class="no-hover border rounded-lg">
                        <tbody>
                            <tr><td>Due</td><td>{{ currentCard.fsrs_due_at }}</td></tr>
                            <tr><td>Stability</td><td>{{ currentCard.fsrs_stability || '-' }}</td></tr>
                            <tr><td>Difficulty</td><td>{{ currentCard.fsrs_difficulty || '-' }}</td></tr>
                            <tr><td>Lapses</td><td>{{ currentCard.fsrs_lapses }}</td></tr>
                        </tbody>
                    </v-simple-table>
                </v-col>
            </v-row>

            <div class="d-flex justify-center flex-wrap mt-6">
                <v-btn depressed rounded color="error" class="ma-2" :disabled="rating" @click="rate('again')">Again</v-btn>
                <v-btn depressed rounded color="warning" class="ma-2" :disabled="rating" @click="rate('hard')">Hard</v-btn>
                <v-btn depressed rounded color="primary" class="ma-2" :disabled="rating" @click="rate('good')">Good</v-btn>
                <v-btn depressed rounded color="success" class="ma-2" :disabled="rating" @click="rate('easy')">Easy</v-btn>
            </div>
        </v-card>

        <v-alert v-else-if="!loading" type="info" dense outlined>
            当前没有到期词义卡。
        </v-alert>
    </v-container>
</template>

<script>
    export default {
        data: function() {
            return {
                loading: false,
                rating: false,
                error: '',
                cards: [],
                summary: {},
                reviewedCount: 0,
            }
        },
        computed: {
            currentCard() {
                return this.cards.length ? this.cards[0] : null;
            },
            remainingCount() {
                return this.cards.length;
            },
        },
        mounted() {
            this.loadCards();
        },
        methods: {
            loadCards() {
                this.loading = true;
                this.error = '';
                axios.get('/reviews/senses').then((response) => {
                    this.cards = response.data.cards;
                    this.summary = response.data.summary;
                }).catch((error) => {
                    this.error = error.response?.data?.message || 'Failed to load sense review queue.';
                }).finally(() => {
                    this.loading = false;
                });
            },
            rate(rating) {
                if (!this.currentCard) {
                    return;
                }

                this.rating = true;
                this.error = '';
                axios.post(`/reviews/senses/${this.currentCard.review_card_id}/rate`, {
                    rating: rating,
                }).then((response) => {
                    this.reviewedCount++;
                    this.summary = response.data.summary;
                    this.loadCards();
                }).catch((error) => {
                    this.error = error.response?.data?.message || 'Failed to rate sense card.';
                }).finally(() => {
                    this.rating = false;
                });
            },
        }
    }
</script>

<style scoped>
    .sense-main {
        font-size: 24px;
        font-weight: 600;
    }
</style>
