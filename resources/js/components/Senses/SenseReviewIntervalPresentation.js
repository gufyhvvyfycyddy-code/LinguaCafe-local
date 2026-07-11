/**
 * SenseReviewIntervalPresentation
 *
 * SenseReview-IntervalPreview-1000-5
 *
 * Pure presentation helpers for the sense review answer interval
 * preview feature. These functions ONLY format already-computed
 * values coming from the backend interval-preview endpoint into
 * user-facing Chinese strings. They contain NO scheduling logic,
 * NO FSRS calculation, NO axios calls, NO Vuex access, and NO DOM
 * access.
 *
 * The actual interval numbers are always produced server-side by
 * FsrsSchedulingService::previewAllRatings() and returned by
 * GET /reviews/senses/{reviewCard}/interval-preview. The frontend
 * must never compute intervals itself.
 *
 * Formatting rules (frozen in ADR-0008):
 *   0–59 seconds        → 小于 1 分钟
 *   1–59 minutes        → N 分钟
 *   1–23 hours          → N 小时
 *   1–59 days           → N 天
 *   60–364 days         → N 个月
 *   365 days or more    → N 年
 *
 * Rounding: Math.round is used within each bucket. When rounding
 * would push a value to the next bucket boundary (e.g. 59.98 min
 * rounds to 60 min), the value naturally flows to the next unit
 * (1 小时). This avoids ever displaying "60 分钟" or "24 小时".
 *
 * Invalid / null / negative input always returns '' (empty string),
 * never NaN, never undefined, never a negative number.
 */

import { RATING_VALUES } from './SenseReviewRatingPresentation.js';

/**
 * Format a non-negative interval in seconds as a short Chinese
 * relative-time string. Returns '' for invalid input.
 *
 * @param {number} seconds
 * @returns {string}
 */
export function formatIntervalSeconds(seconds) {
    if (typeof seconds !== 'number' || !isFinite(seconds) || seconds < 0) {
        return '';
    }

    if (seconds < 60) {
        return '小于 1 分钟';
    }

    const minutes = seconds / 60;
    const roundedMinutes = Math.round(minutes);
    if (roundedMinutes < 60) {
        return roundedMinutes + ' 分钟';
    }

    const hours = seconds / 3600;
    const roundedHours = Math.round(hours);
    if (roundedHours < 24) {
        return roundedHours + ' 小时';
    }

    const days = seconds / 86400;
    const roundedDays = Math.round(days);
    if (roundedDays < 60) {
        return roundedDays + ' 天';
    }

    if (roundedDays < 365) {
        return Math.round(roundedDays / 30) + ' 个月';
    }

    return Math.round(roundedDays / 365) + ' 年';
}

/**
 * Format an ISO 8601 due-at timestamp as a tooltip string showing
 * the predicted absolute review time. The timestamp is rendered in
 * the browser's local timezone (the ISO string carries the full
 * offset). The optional timezone parameter is accepted for API
 * completeness but the browser-local rendering is authoritative
 * since that is what the user actually experiences.
 *
 * Returns '' for invalid / null input.
 *
 * @param {string} iso
 * @param {string} [timezone]
 * @returns {string}
 */
export function formatDueAtTooltip(iso, timezone) {
    if (!iso) {
        return '';
    }
    const d = new Date(iso);
    if (isNaN(d.getTime())) {
        return '';
    }
    const pad = (n) => String(n).padStart(2, '0');
    const text = d.getFullYear()
        + '-' + pad(d.getMonth() + 1)
        + '-' + pad(d.getDate())
        + ' ' + pad(d.getHours())
        + ':' + pad(d.getMinutes());
    return '预计 ' + text;
}

/**
 * Normalize a raw interval-preview API payload into a per-rating
 * presentational map suitable for direct use by
 * SenseReviewRatingControls.
 *
 * Input shape (from GET /reviews/senses/{reviewCard}/interval-preview):
 *   {
 *     review_card_id: number,
 *     generated_at: string,
 *     timezone: string,
 *     engine: 'fsrs' | 'fallback',
 *     ratings: {
 *       again: { due_at: string, interval_seconds: number, next_state: string },
 *       hard:  { ... },
 *       good:  { ... },
 *       easy:  { ... },
 *     }
 *   }
 *
 * Output shape:
 *   {
 *     again: { text: '预计 10 分钟', tooltip: '预计 2026-07-11 18:10' },
 *     hard:  { text: '预计 1 天',    tooltip: '预计 2026-07-12 18:10' },
 *     good:  { ... },
 *     easy:  { ... },
 *   }
 *
 * Missing or invalid ratings produce { text: '', tooltip: '' } so
 * the UI can show a graceful fallback without crashing.
 *
 * @param {object} payload
 * @returns {object}
 */
export function normalizeIntervalPreview(payload) {
    const result = {};
    const ratings = (payload && payload.ratings) || {};

    RATING_VALUES.forEach((rating) => {
        const entry = ratings[rating];
        if (!entry || typeof entry.interval_seconds !== 'number') {
            result[rating] = { text: '', tooltip: '' };
            return;
        }
        const intervalText = formatIntervalSeconds(entry.interval_seconds);
        result[rating] = {
            text: intervalText ? '预计 ' + intervalText : '',
            tooltip: formatDueAtTooltip(entry.due_at),
        };
    });

    return result;
}
