<template>
    <div class="mt-5">
        <div class="d-flex align-center mb-2">
            <v-icon x-small class="mr-1">{{ icon }}</v-icon>
            <span class="text-subtitle-1 font-weight-medium">{{ title }}</span>
            <v-spacer />
            <v-btn
                x-small
                rounded
                depressed
                color="primary"
                @click="$emit('copy')"
            >
                <v-icon x-small class="mr-1">mdi-content-copy</v-icon>
                {{ copyButtonLabel }}
            </v-btn>
        </div>
        <v-alert
            v-if="copyMessage"
            dense
            text
            :type="copied ? 'success' : 'error'"
            class="mb-2"
        >{{ copyMessage }}</v-alert>
        <v-alert
            dense
            text
            type="warning"
            class="mb-2"
        >{{ warningText }}</v-alert>
        <pre class="pa-3 rounded text-caption" style="background: var(--v-gray1-base); max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-all;">{{ JSON.stringify(pkg, null, 2) }}</pre>
    </div>
</template>

<script>
/**
 * AiStudyCardPackagePanel
 * =======================
 * Presentational sub-component for displaying a JSON package (V3 safe preview
 * package or V4 final candidates package) with a copy button.
 *
 * Design rules:
 *   - Pure presentational (props in, events out).
 *   - Does NOT call axios.
 *   - Does NOT import Vuex / mapState.
 *   - Does NOT call clipboard APIs directly — emits `copy` and lets the
 *     parent decide how to copy (via AiStudyCardClipboardService).
 *   - Does NOT know about SideBox / Box / parent internals.
 *
 * Events:
 *   - copy ()
 *
 * (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4)
 */
export default {
    name: 'AiStudyCardPackagePanel',
    props: {
        title: { type: String, required: true },
        icon: { type: String, default: 'mdi-package-variant-closed' },
        copyButtonLabel: { type: String, default: '复制' },
        pkg: { type: Object, default: null },
        copyMessage: { type: String, default: '' },
        copied: { type: Boolean, default: false },
        warningText: { type: String, default: '' },
    },
};
</script>
