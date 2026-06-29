/**
 * ReaderWorkspaceSizingService
 * =============================
 * Pure functions for reader page width calculations.
 *
 * All functions are deterministic: given the same `width` input (number, px),
 * they always return the same output. They do NOT access DOM, Vue, Vuex, window,
 * or any external state.
 *
 * Breakpoint rules are defined once here and shared across TextReader.vue,
 * VocabularySideBox.vue, and TextBlockGroup.vue.
 */

/**
 * Return the sidebar width (in px) for a given workspace width.
 *
 * @param {number} width - Workspace width in px (e.g. `#fullscreen-box.clientWidth`)
 * @returns {number} Sidebar width in px
 */
export function getReaderSidebarWidthForWorkspace(width) {
    if (width >= 1500) return 600;
    if (width >= 1280) return 560;
    if (width >= 1080) return 520;
    return 400;
}

/**
 * Return the sidebar width as a CSS string (e.g. '600px', '560px').
 *
 * @param {number} width - Workspace width in px
 * @returns {string} Sidebar width CSS value
 */
export function getReaderSidebarCssWidthForWorkspace(width) {
    return getReaderSidebarWidthForWorkspace(width) + 'px';
}

/**
 * Return the minimum reader content area width (in px).
 * Below this width the sidebar will not fit.
 *
 * @param {number} width - Workspace width in px
 * @returns {number} Minimum reader width in px
 */
export function getMinimumReaderWidthForWorkspace(width) {
    return width >= 1280 ? 720 : 560;
}

/**
 * Check whether the sidebar can fit within the workspace alongside the
 * reader content area, with optional spacing.
 *
 * @param {number} width - Workspace width in px
 * @param {number} [spacing=72] - Additional spacing in px
 * @returns {boolean} True if sidebar fits
 */
export function doesReaderSidebarFitWorkspace(width, spacing = 72) {
    const sidebarWidth = getReaderSidebarWidthForWorkspace(width);
    const minimumReaderWidth = getMinimumReaderWidthForWorkspace(width);
    return width >= minimumReaderWidth + sidebarWidth + spacing;
}
