export async function mediaSlotKey(role, sentence = '') {
    const value = role === 'word_pronunciation' ? 'word' : String(sentence || '').trim();
    const bytes = new TextEncoder().encode(value);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

export function selectMedia(items, role, slotKey = null, sourceText = null) {
    const candidates = Array.isArray(items) ? items.filter((item) => item.role === role) : [];
    if (sourceText !== null) {
        const normalized = String(sourceText || '').trim();
        return candidates.find((item) => String(item.source_text || '').trim() === normalized) || null;
    }
    if (slotKey) {
        return candidates.find((item) => item.slot_key === slotKey) || null;
    }
    return candidates[0] || null;
}
