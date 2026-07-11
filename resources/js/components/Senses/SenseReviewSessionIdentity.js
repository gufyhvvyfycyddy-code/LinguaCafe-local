// SenseReviewSessionIdentity.js
//
// ADR-0009: Review action transaction ledger and stack-based undo.
//
// Pure-function helper for the SenseReview review-session identity.
// No Vue dependency, no axios, no network calls. Uses sessionStorage
// so each browser tab gets its own session ID, surviving page refresh
// but not tab close (per ADR-0009 stack-undo scope: current tab only).
//
// localStorage is NOT used — undo scope must not leak across tabs.
//
// Shape:
//   reviewSessionId = UUID v4 string (e.g. "550e8400-e29b-41d4-a716-446655440000")
//
// Exports:
//   getOrCreateReviewSessionId()  — returns existing or generates new UUID
//   isValidReviewSessionId(id)    — validates UUID v4 format
//   clearReviewSessionId()        — removes the session ID from sessionStorage

const STORAGE_KEY = 'sense_review_session_id';

/**
 * Validate that a string is a well-formed UUID v4.
 * Accepts both lowercase and uppercase hex. Does NOT query storage.
 *
 * @param  {string}  id
 * @return {boolean}
 */
export function isValidReviewSessionId(id) {
    if (typeof id !== 'string' || id.length !== 36) {
        return false;
    }
    // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
    // where y is 8, 9, a, or b.
    return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(id);
}

/**
 * Get the existing review session ID from sessionStorage, or generate
 * a new UUID v4 and store it. The ID persists across page refreshes
 * within the same tab, but is NOT shared across tabs.
 *
 * Uses crypto.randomUUID() when available (modern browsers). Falls back
 * to a manual RFC 4122 v4 generator for older environments.
 *
 * @return {string} UUID v4 string
 */
export function getOrCreateReviewSessionId() {
    try {
        const existing = sessionStorage.getItem(STORAGE_KEY);
        if (existing && isValidReviewSessionId(existing)) {
            return existing;
        }
    } catch (e) {
        // sessionStorage may be unavailable (private mode, etc.).
        // Fall through to generate an in-memory ID.
    }

    const id = generateUuidV4();
    try {
        sessionStorage.setItem(STORAGE_KEY, id);
    } catch (e) {
        // If sessionStorage is unavailable, the ID is still valid for
        // the current page load — it just won't survive refresh.
    }
    return id;
}

/**
 * Clear the review session ID from sessionStorage. Mainly useful for
 * testing or when the user explicitly starts a "new session" (not
 * currently exposed in the UI, but available for future use).
 */
export function clearReviewSessionId() {
    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch (e) {
        // No-op if sessionStorage is unavailable.
    }
}

/**
 * Generate a UUID v4 string. Uses crypto.randomUUID() when available,
 * otherwise falls back to a manual implementation using
 * crypto.getRandomValues.
 *
 * @return {string} UUID v4 string
 * @private
 */
function generateUuidV4() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    // Manual fallback for environments without crypto.randomUUID.
    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(bytes);
    } else {
        // Last-resort fallback (non-cryptographic, but sufficient for
        // a per-tab session ID that is not security-sensitive).
        for (let i = 0; i < 16; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    // Set version (4) and variant (10xx) bits per RFC 4122.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = [];
    for (let i = 0; i < 16; i++) {
        hex.push(bytes[i].toString(16).padStart(2, '0'));
    }

    return (
        hex.slice(0, 4).join('') + '-' +
        hex.slice(4, 6).join('') + '-' +
        hex.slice(6, 8).join('') + '-' +
        hex.slice(8, 10).join('') + '-' +
        hex.slice(10, 16).join('')
    );
}
