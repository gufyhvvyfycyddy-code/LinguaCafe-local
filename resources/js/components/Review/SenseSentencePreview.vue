<template>
    <div class="sense-sentence-preview">
        <template v-if="tokens">
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
                :stage="token.stage"
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
            isTargetToken(token) {
                if (!token || !token.word) {
                    return false;
                }
                const tokenWord = token.word.toLowerCase();
                const surface = (this.targetSurface || '').toLowerCase();
                const lemma = (this.targetLemma || '').toLowerCase();
                return tokenWord === surface || tokenWord === lemma;
            },
        },
    };
</script>
