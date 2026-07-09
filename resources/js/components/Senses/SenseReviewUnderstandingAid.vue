<template>
    <!--
        SenseReviewUnderstandingAid — collapsible understanding-aid block.

        Pure presentational component. Receives the normalized `aid` object
        from the parent (already merged with occurrence-level evidence by
        the backend serializer) and renders the collapsible "理解这个词义"
        section. Owns only its own collapse state.

        Contract:
         - No backend calls.
         - No ReviewLog writes.
         - No FSRS changes.
         - No emits: the parent has no need to observe collapse state.
    -->
    <div v-if="hasAnyContent" class="mt-4">
        <div
            class="caption text--secondary d-flex align-center"
            style="cursor: pointer;"
            @click="open = !open"
        >
            <v-icon small class="mr-1">{{ open ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
            理解这个词义
        </div>
        <v-expand-transition>
            <div v-if="open" class="mt-2">
                <div v-if="aid.explanation" class="body-2 mb-2">
                    {{ aid.explanation }}
                </div>
                <div v-if="aid.meaning_boundary" class="mb-2">
                    <span class="caption text--secondary">词义边界：</span>
                    <span class="body-2">{{ aid.meaning_boundary }}</span>
                </div>
                <div v-if="aid.context_hint" class="mb-2">
                    <span class="caption text--secondary">上下文提示：</span>
                    <span class="body-2">{{ aid.context_hint }}</span>
                </div>
                <div v-if="aid.usage_keywords && aid.usage_keywords.length">
                    <span class="caption text--secondary">判断依据：</span>
                    <div class="mt-1">
                        <v-chip
                            small
                            class="mr-1 mb-1"
                            v-for="kw in aid.usage_keywords"
                            :key="kw"
                        >{{ kw }}</v-chip>
                    </div>
                </div>
                <div v-if="aid.related_collocations && aid.related_collocations.length" class="mt-2">
                    <span class="caption text--secondary">类似使用：</span>
                    <div class="mt-1">
                        <v-chip
                            small
                            outlined
                            class="mr-1 mb-1"
                            v-for="col in aid.related_collocations"
                            :key="col"
                        >{{ col }}</v-chip>
                    </div>
                </div>
            </div>
        </v-expand-transition>
    </div>
</template>

<script>
    /**
     * Props:
     *  - aid: normalized understanding_aid object from the serializer:
     *    { explanation, meaning_boundary, context_hint, usage_keywords[], related_collocations[] }
     *
     * The component only renders when at least one sub-field has content
     * (hasAnyContent computed). Default collapsed.
     */
    export default {
        name: 'SenseReviewUnderstandingAid',
        props: {
            aid: {
                type: Object,
                default: () => ({}),
            },
        },
        data() {
            return {
                open: false,
            };
        },
        computed: {
            hasAnyContent() {
                if (!this.aid) {
                    return false;
                }
                return !!(
                    this.aid.explanation ||
                    this.aid.meaning_boundary ||
                    this.aid.context_hint ||
                    (Array.isArray(this.aid.usage_keywords) && this.aid.usage_keywords.length) ||
                    (Array.isArray(this.aid.related_collocations) && this.aid.related_collocations.length)
                );
            },
        },
    }
</script>
