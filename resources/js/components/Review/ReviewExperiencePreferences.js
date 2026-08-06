export const REVIEW_EXPERIENCE_STORAGE_KEY = 'linguacafe-review-experience-v1';

export function defaultReviewExperiencePreferences(reduceMotion = false) {
    return { fontSize: 20, highContrast: false, reduceMotion: !!reduceMotion };
}

export function normalizeReviewExperiencePreferences(value, defaults = defaultReviewExperiencePreferences()) {
    const input = value && typeof value === 'object' ? value : {};
    const fontSize = Number(input.fontSize);
    return {
        fontSize: Number.isInteger(fontSize) && fontSize >= 16 && fontSize <= 32
            ? fontSize
            : defaults.fontSize,
        highContrast: typeof input.highContrast === 'boolean' ? input.highContrast : defaults.highContrast,
        reduceMotion: typeof input.reduceMotion === 'boolean' ? input.reduceMotion : defaults.reduceMotion,
    };
}

export function loadReviewExperiencePreferences(storage, reduceMotion = false) {
    const defaults = defaultReviewExperiencePreferences(reduceMotion);
    try {
        return normalizeReviewExperiencePreferences(
            JSON.parse(storage.getItem(REVIEW_EXPERIENCE_STORAGE_KEY) || 'null'),
            defaults,
        );
    } catch (_) {
        return defaults;
    }
}

export function saveReviewExperiencePreferences(storage, preferences) {
    const normalized = normalizeReviewExperiencePreferences(preferences);
    try {
        storage.setItem(REVIEW_EXPERIENCE_STORAGE_KEY, JSON.stringify(normalized));
    } catch (_) {
        // Browser privacy/storage failures must not block review.
    }
    return normalized;
}
