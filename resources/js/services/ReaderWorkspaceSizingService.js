/**
 * ReaderWorkspaceSizingService
 * =============================
 * Pure functions for reader page width calculations.
 *
 * All functions are deterministic: given the same `width` input (number, px),
 * they always return the same output. They do NOT access DOM, Vue, Vuex, window,
 * or any external state.
 *
 * Breakpoint rules are defined once here and shared across TextReader.vue
 * and VocabularySideBox.vue. TextBlockGroup.vue is not yet converged — if
 * it needs the same helper, that must go through a separate architecture gate.
 */

/**
 * Return the sidebar width (in px) for a given workspace width.
 *
 * @param {number} width - Workspace width in px (e.g. `#fullscreen-box.clientWidth`)
 * @returns {number} Sidebar width in px
 */
export function getReaderSidebarWidthForWorkspace(width) {
    if (width >= 1500) return 540;
    if (width >= 1280) return 500;
    if (width >= 1080) return 460;
    return 400;
}

/**
 * Return the full horizontal track reserved for the sidebar.
 *
 * The 24px difference keeps the sidebar's rounded outer edge visible instead
 * of letting the panel touch the reader card boundary.
 *
 * @param {number} width - Workspace width in px
 * @returns {number} Reserved sidebar track width in px
 */
export function getReaderSidebarReservationWidthForWorkspace(width) {
    return getReaderSidebarWidthForWorkspace(width) + 24;
}

/**
 * Return the sidebar panel width as a CSS string (e.g. '540px', '500px').
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
    const sidebarReservationWidth = getReaderSidebarReservationWidthForWorkspace(width);
    const minimumReaderWidth = getMinimumReaderWidthForWorkspace(width);
    return width >= minimumReaderWidth + sidebarReservationWidth + spacing;
}

export function isReaderSidebarVisible({ enabled, fits, active, hidden }) {
    return Boolean(enabled && fits && active && !hidden);
}

export function getReaderContentRightPadding({ enabled, fits, active, hidden, workspaceWidth }) {
    if (!isReaderSidebarVisible({ enabled, fits, active, hidden })) {
        return '0px';
    }

    return getReaderSidebarReservationWidthForWorkspace(workspaceWidth) + 'px';
}
