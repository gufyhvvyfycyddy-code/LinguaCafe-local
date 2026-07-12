<template>
    <!--
        SenseReviewLeechPanel — ADR-0011 (Task A-1)

        Read-only leech governance panel shown on the SenseReview review
        page. Fetches the leech descriptor from
        GET /reviews/senses/{reviewCardId}/leech and renders:

         - stable   → hidden (renders nothing)
         - struggling → a light warning alert, visible even before the
                       answer is revealed
         - leech    → a governance card shown ONLY when showAnswer is true,
                       with severity badge, reason chips, and four action
                       buttons that emit events to the parent.

        Contract:
         - Emits 'rewrite' | 'edit' | 'history' | 'suspend' (parent owns
           the actual API calls).
         - Does NOT block rating. Does NOT change hotkeys.
         - Does NOT call AI. Does NOT auto-suspend.
         - Does NOT mutate frontend state on error.
    -->
    <div v-if="descriptor && descriptor.status !== 'stable'">
        <!-- Struggling: light warning alert, always visible. -->
        <v-alert
            v-if="descriptor.status === 'struggling'"
            type="warning"
            dense
            text
            class="mt-3 mb-0"
            border="left"
        >
            <div class="font-weight-medium">{{ strugglingHintText() }}</div>
            <div class="text-body-2 mt-1">{{ strugglingSuggestionText() }}</div>
        </v-alert>

        <!-- Leech: governance card, only when answer is shown. -->
        <v-card
            v-else-if="descriptor.status === 'leech' && showAnswer"
            outlined
            class="mt-3 leech-governance-card"
        >
            <v-card-title class="d-flex align-center flex-wrap pa-3">
                <v-icon small color="error" class="mr-2">mdi-alert-circle-outline</v-icon>
                <span class="text-subtitle-1 font-weight-medium">{{ leechPanelText() }}</span>
                <v-spacer />
                <v-chip
                    x-small
                    :color="severityColor(descriptor.severity)"
                    text-color="white"
                    class="ml-2"
                >
                    严重度：{{ severityText(descriptor.severity) }}
                </v-chip>
            </v-card-title>
            <v-card-text class="pa-3 pt-0">
                <div v-if="descriptor.reasons && descriptor.reasons.length" class="mb-3">
                    <div class="text-caption text--secondary mb-1">原因</div>
                    <v-chip
                        v-for="reason in descriptor.reasons"
                        :key="reason"
                        x-small
                        color="error"
                        outlined
                        class="mr-1 mb-1"
                    >{{ reasonLabel(reason) }}</v-chip>
                </div>
                <div v-if="descriptor.suggestions && descriptor.suggestions.length" class="mb-3">
                    <div class="text-caption text--secondary mb-1">建议</div>
                    <v-chip
                        v-for="suggestion in descriptor.suggestions"
                        :key="suggestion"
                        x-small
                        outlined
                        class="mr-1 mb-1"
                    >{{ suggestionLabel(suggestion) }}</v-chip>
                </div>
                <v-divider class="my-2" />
                <div class="d-flex flex-wrap" style="gap: 8px;">
                    <v-btn x-small color="primary" @click="$emit('rewrite')">
                        <v-icon x-small left>mdi-package-variant-closed</v-icon>
                        生成重写提示包
                    </v-btn>
                    <v-btn x-small text @click="$emit('edit')">
                        <v-icon x-small left>mdi-pencil</v-icon>
                        编辑词义
                    </v-btn>
                    <v-btn x-small text @click="$emit('history')">
                        <v-icon x-small left>mdi-history</v-icon>
                        查看历史
                    </v-btn>
                    <v-btn
                        x-small
                        text
                        color="warning"
                        :disabled="isSuspendBlocked"
                        @click="$emit('suspend')"
                    >
                        <v-icon x-small left>mdi-pause-circle-outline</v-icon>
                        暂停复习
                    </v-btn>
                </div>
            </v-card-text>
        </v-card>
    </div>
</template>

<script>
import {
    statusLabel,
    statusColor,
    reasonLabel,
    suggestionLabel,
    severityText,
    severityColor,
    strugglingHintText,
    strugglingSuggestionText,
    leechPanelText,
} from '../../services/SenseReviewLeechPresentation.js';

export default {
    name: 'SenseReviewLeechPanel',
    props: {
        reviewCardId: {
            type: Number,
            required: true,
        },
        showAnswer: {
            type: Boolean,
            required: true,
        },
    },
    data() {
        return {
            descriptor: null,
            loading: false,
            error: '',
            // Race-protection counter: bumped on every new fetch so stale
            // responses are discarded.
            requestSequence: 0,
        };
    },
    computed: {
        // Whether suspend is blocked by governance (e.g. already suspended).
        isSuspendBlocked() {
            if (!this.descriptor) {
                return false;
            }
            const blocked = this.descriptor.blocked_actions || [];
            return blocked.indexOf('suspend_temporarily') !== -1;
        },
    },
    watch: {
        // Re-fetch when the card changes. Bumps the sequence so any
        // in-flight response for the previous card is discarded.
        reviewCardId(newId, oldId) {
            if (newId !== oldId) {
                this.fetchDescriptor();
            }
        },
    },
    mounted() {
        this.fetchDescriptor();
    },
    methods: {
        fetchDescriptor() {
            if (!this.reviewCardId) {
                this.descriptor = null;
                return;
            }
            this.requestSequence++;
            const seq = this.requestSequence;
            this.loading = true;
            this.error = '';
            axios.get('/reviews/senses/' + this.reviewCardId + '/leech')
                .then((response) => {
                    if (seq !== this.requestSequence) {
                        return;
                    }
                    const data = response.data || {};
                    this.descriptor = data.leech || null;
                })
                .catch(() => {
                    if (seq !== this.requestSequence) {
                        return;
                    }
                    // Non-blocking: on failure, hide the panel entirely.
                    // Rating is never blocked by leech diagnostics.
                    this.descriptor = null;
                    this.error = '';
                })
                .finally(() => {
                    if (seq !== this.requestSequence) {
                        return;
                    }
                    this.loading = false;
                });
        },
        // Thin wrappers exposing the pure presentation helpers to the
        // template. Vue 2 templates can only call functions registered
        // on the instance via methods.
        statusLabel,
        statusColor,
        reasonLabel,
        suggestionLabel,
        severityText,
        severityColor,
        strugglingHintText,
        strugglingSuggestionText,
        leechPanelText,
    },
};
</script>

<style scoped>
.leech-governance-card {
    border-left: 4px solid #ef5350;
}
</style>
