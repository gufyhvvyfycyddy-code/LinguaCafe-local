/**
 * AiStudyCardClipboardService
 * ===========================
 * Centralized clipboard helper for the AI Study Card workflow. Encapsulates
 * the navigator.clipboard API with a textarea fallback for older browsers
 * / insecure contexts.
 *
 * Design rules:
 *   - Pure function (no Vue, no Vuex store, no axios).
 *   - The only side effect is DOM interaction (textarea fallback) and the
 *     clipboard write. DOM fallback is concentrated HERE so that no other
 *     workflow module needs to touch `document.createElement` /
 *     `document.execCommand('copy')`.
 *   - Returns a Promise that resolves to `{ ok: boolean, message: string }`.
 *
 * Why this service exists (GM52-AIStudyCardDesktopWorkflowDeepModuleSplit-1000-4):
 *   Previously the AiStudyCardDesktopWorkflow component carried its own
 *   `copyJsonToClipboard` method with inline `document.createElement('textarea')`
 *   + `document.execCommand('copy')` fallback. That DOM logic leaked into a
 *   component that should only coordinate state. This service gives clipboard
 *   behavior a single entry point so the container can stay thin.
 */

/**
 * Copy text to the system clipboard.
 *
 * Tries `navigator.clipboard.writeText` first. If that API is unavailable
 * (older browser / insecure context) or the promise rejects, falls back to
 * a hidden textarea + `document.execCommand('copy')`.
 *
 * @param {string} text The text to copy.
 * @returns {Promise<{ ok: boolean, message: string }>}
 */
export function copyTextToClipboard(text) {
    const value = typeof text === 'string' ? text : String(text == null ? '' : text);

    if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        return navigator.clipboard.writeText(value).then(() => ({
            ok: true,
            message: '已复制到剪贴板。',
        })).catch(() => fallbackCopyText(value));
    }

    return Promise.resolve(fallbackCopyText(value));
}

/**
 * Hidden-textarea fallback used when navigator.clipboard is unavailable.
 *
 * @param {string} text
 * @returns {{ ok: boolean, message: string }}
 */
function fallbackCopyText(text) {
    try {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        // document.execCommand is deprecated but still the most reliable
        // fallback for insecure contexts (http://) where navigator.clipboard
        // is gated behind a secure context.
        document.execCommand('copy');
        document.body.removeChild(textarea);
        return { ok: true, message: '已复制到剪贴板。' };
    } catch (e) {
        return { ok: false, message: '复制失败，请手动选择文本复制。' };
    }
}
