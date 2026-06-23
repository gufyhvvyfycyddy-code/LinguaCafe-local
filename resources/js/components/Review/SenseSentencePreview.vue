<template>
    <div class="text-block-group sense-sentence-preview">
        <template v-if="hasTokens()">
            <span
                v-for="(token, index) in tokens"
                :key="index"
                :class="[
                    'word',
                    'selected-font',
                    {
                        'space-after': token.spaceAfter,
                        'sense-target-token': isTargetToken(token)
                    }
                ]"
                :stage="token.stage === undefined || token.stage === null ? 2 : token.stage"
            >{{ token.word }}</span>
        </template>
        <span v-else class="sense-sentence-fallback">
            {{ sentenceText || fallbackText }}
        </span>
    </div>
</template>

<script>
    export default {
        props: {
            tokens: {
                type: Array,
                default: null,
            },
            sentenceText: {
                type: String,
                default: '',
            },
            targetSurface: {
                type: String,
                default: '',
            },
            targetLemma: {
                type: String,
                default: '',
            },
            language: {
                type: String,
                default: '',
            },
            fontSize: {
                type: Number,
                default: 20,
            },
            fallbackText: {
                type: String,
                default: '',
            },
        },
        methods: {
            hasTokens() {
                return Array.isArray(this.tokens) && this.tokens.length > 0;
            },
            isTargetToken(token) {
                if (!token || !token.word) {
                    return false;
                }
                // Priority: is_target flag from backend
                if (token.is_target) {
                    return true;
                }
                const tokenWord = token.word.toLowerCase();
                const surface = (this.targetSurface || '').toLowerCase();
                const lemma = (this.targetLemma || '').toLowerCase();
                return tokenWord === surface || tokenWord === lemma;
            },
        },
    };
</script>
