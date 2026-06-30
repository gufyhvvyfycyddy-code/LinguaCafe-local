/**
 * TextWordTargetService
 * =====================
 * Pure DOM-parsing helpers for resolving a user input target (mouse / touch
 * event target, or a point via document.elementFromPoint) to the nearest
 * rendered word element and its `wordindex` attribute inside TextBlockGroup.
 *
 * These helpers centralize the previously duplicated "target -> word element
 * -> wordIndex" logic that existed in startSelectionTouchEvent,
 * startSelectionMouseEvent, updateSelectionTouchEvent and
 * updateSelectionMouseEvent. They only READ the DOM; they never mutate it,
 * never import Vue, never read Vuex, never call axios, and never trigger
 * selection / lookup / save flows.
 *
 * Word elements in TextBlockGroup are rendered as:
 *   - <span class="word ..." :wordindex="..."> for plain / structure words
 *   - <ruby class="rubyword ..." :wordindex="..."> for Japanese furigana
 *     (always nested inside a .word span)
 *   - <rt> inside <ruby class="rubyword"> for furigana annotations
 */

/**
 * Resolve an event target (or any node) to the nearest rendered word element.
 *
 * Resolution order:
 *   1. If target is a Text node, move to its parentElement.
 *   2. If element is a <ruby>, move to its parentElement (the .word span).
 *   3. If element is an <rt>, move to ruby's parentElement (the .word span).
 *   4. If element itself is .word or .rubyword, return it.
 *   5. Otherwise walk up via closest('.word, .rubyword').
 *
 * @param {Node|null|undefined} target - event.target or any DOM node
 * @returns {Element|null} The matched word element, or null if none found
 */
export function resolveWordElementFromEventTarget(target) {
    // Text node -> parentElement
    let element = target instanceof Element ? target : target?.parentElement;
    if (!element) {
        return null;
    }

    // ruby -> its parent (.word span); rt -> ruby's parent (.word span)
    if (element.localName === 'ruby') {
        element = element.parentElement;
    } else if (element.localName === 'rt') {
        const ruby = element.parentElement;
        element = ruby ? ruby.parentElement : null;
    }

    if (!element) {
        return null;
    }

    // Already on a .word or .rubyword
    if (element.classList && (element.classList.contains('word') || element.classList.contains('rubyword'))) {
        return element;
    }

    // Fallback: walk up to the nearest .word or .rubyword ancestor
    if (element.closest) {
        return element.closest('.word, .rubyword');
    }

    return null;
}

/**
 * Read and parse the `wordindex` attribute from a word element.
 *
 * @param {Element|null|undefined} element - a word element from resolveWordElementFromEventTarget
 * @returns {number} The parsed wordIndex, or -1 if element is invalid / missing / NaN
 */
export function readWordIndexFromElement(element) {
    if (!element || !element.getAttribute) {
        return -1;
    }
    const raw = element.getAttribute('wordindex');
    if (raw === null || raw === undefined) {
        return -1;
    }
    const value = parseInt(raw, 10);
    return Number.isNaN(value) ? -1 : value;
}

/**
 * Resolve an event target directly to a wordIndex.
 *
 * Convenience wrapper around resolveWordElementFromEventTarget +
 * readWordIndexFromElement. Returns -1 if no word element is found or the
 * attribute is missing / unparseable.
 *
 * @param {Node|null|undefined} target - event.target or any DOM node
 * @returns {number} wordIndex, or -1
 */
export function resolveWordIndexFromEventTarget(target) {
    const element = resolveWordElementFromEventTarget(target);
    return readWordIndexFromElement(element);
}

/**
 * Resolve a viewport point (clientX, clientY) to the word element under it.
 *
 * Uses document.elementFromPoint to find the topmost element at the point,
 * then applies the same resolution rules as
 * resolveWordElementFromEventTarget. Returns null if there is no element at
 * the point or no word element can be resolved.
 *
 * @param {number} x - clientX (viewport-relative)
 * @param {number} y - clientY (viewport-relative)
 * @returns {Element|null} The matched word element, or null
 */
export function resolveWordElementFromPoint(x, y) {
    const raw = document.elementFromPoint(x, y);
    if (!raw) {
        return null;
    }
    return resolveWordElementFromEventTarget(raw);
}
