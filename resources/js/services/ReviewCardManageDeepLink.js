/**
 * ReviewCardManageDeepLink
 *
 * ADR-0007 — SenseReview Report Card Deep Link
 *
 * Pure functions for building and parsing review-card-management deep-link
 * route locations. Used by SenseReviewReportCenter to navigate from a daily
 * report entry to the exact card management detail.
 *
 * Route contract:
 *   /review-cards/manage?review_card_id={positive-int}&from={source-whitelist}
 *
 * Contract:
 *  - PURE: no axios, no Vue, no Vuex, no DOM, no state writes.
 *  - review_card_id must be a positive integer.
 *  - word_sense_id may be carried as a diagnostic field but NEVER replaces
 *    review_card_id for navigation.
 *  - source is restricted to a whitelist.
 *  - Invalid / missing / zero / negative / string-garbage → returns null.
 *  - Never calls an API.
 *
 * Inspired by Anki's Browser / Card Info: open the exact card by ID, not
 * a fuzzy text search.
 */

/**
 * Whitelist of valid source labels for the `from` query parameter.
 * Each source identifies which report the user came from, so the management
 * page can show the correct "返回" button label.
 */
const VALID_SOURCES = ['daily-report', 'seven-day-trend', 'thirty-day-calendar'];

/**
 * Build a router location object for the review-card management page with
 * a deep-link query.
 *
 * @param {object}  target  Must contain { review_card_id: number, word_sense_id?: number }.
 * @param {string}  source  One of VALID_SOURCES.
 * @returns {{ path: string, query: { review_card_id: number, from: string } } | null}
 */
export function buildReviewCardManageLocation(target, source) {
    if (!target || typeof target !== 'object') return null;

    const cardId = target.review_card_id;
    if (typeof cardId !== 'number' || !Number.isInteger(cardId) || cardId <= 0) {
        return null;
    }

    if (!VALID_SOURCES.includes(source)) return null;

    return {
        path: '/review-cards/manage',
        query: {
            review_card_id: cardId,
            from: source,
        },
    };
}

/**
 * Parse a route query object (e.g. this.$route.query) into a structured
 * deep-link target, or null if the query is invalid / missing.
 *
 * @param {object} query  Route query object (key→string|string[]).
 * @returns {{ review_card_id: number, from: string } | null}
 */
export function parseReviewCardManageLocation(query) {
    if (!query || typeof query !== 'object') return null;

    const rawId = query.review_card_id;
    if (rawId === undefined || rawId === null) return null;

    const candidateId = Array.isArray(rawId) ? rawId[0] : rawId;
    if (typeof candidateId !== 'string' && typeof candidateId !== 'number') return null;
    const normalizedId = String(candidateId);
    if (!/^\d+$/.test(normalizedId)) return null;
    const cardId = Number(normalizedId);
    if (!Number.isSafeInteger(cardId) || cardId <= 0) return null;

    const rawFrom = query.from;
    const from = Array.isArray(rawFrom) ? rawFrom[0] : rawFrom;
    if (!from || !VALID_SOURCES.includes(from)) return null;

    return {
        review_card_id: cardId,
        from: from,
    };
}

/**
 * Remove only this feature's route keys while preserving unrelated query
 * state such as Saved Search identifiers.
 *
 * @param {object} query
 * @returns {object}
 */
export function stripReviewCardManageDeepLinkQuery(query) {
    if (!query || typeof query !== 'object') return {};
    const nextQuery = { ...query };
    delete nextQuery.review_card_id;
    delete nextQuery.from;
    return nextQuery;
}

/**
 * The list of valid source labels (for testing / display).
 */
export const DEEP_LINK_SOURCES = VALID_SOURCES;
