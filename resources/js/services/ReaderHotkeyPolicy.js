/**
 * Resolve established Reader keyboard facts into one effect intent.
 *
 * This policy owns no DOM inspection or effect execution. The Reader component
 * supplies plain context flags and remains responsible for every resulting
 * action.
 */
export function resolveReaderHotkey({
    enabled,
    which,
    ctrlKey = false,
    metaKey = false,
    altKey = false,
    shiftKey = false,
    editableTarget = false,
    blockingSurface = false,
} = {}) {
    if (!enabled || ctrlKey || metaKey || altKey || editableTarget || blockingSurface) {
        return null;
    }

    if (which >= 48 && which <= 55) {
        return {
            action: 'set-stage',
            stage: 48 - which,
            preventDefault: true,
        };
    }

    if (which >= 96 && which <= 103) {
        return {
            action: 'set-stage',
            stage: 96 - which,
            preventDefault: true,
        };
    }

    switch (which) {
        case 86:
            return { action: 'text-to-speech', preventDefault: false };
        case 67:
            return { action: 'set-stage', stage: 2, preventDefault: false };
        case 88:
            return { action: 'set-stage', stage: 1, preventDefault: true };
        case 73:
            return shiftKey
                ? null
                : { action: 'decrease-font-size', preventDefault: false };
        case 79:
            return { action: 'increase-font-size', preventDefault: true };
        case 38:
        case 87:
            return {
                action: 'scroll',
                direction: 'up',
                accelerated: shiftKey,
                preventDefault: true,
            };
        case 40:
        case 83:
            return {
                action: 'scroll',
                direction: 'down',
                accelerated: shiftKey,
                preventDefault: true,
            };
        case 70:
            return { action: 'add-to-anki', preventDefault: true };
        case 27:
            return { action: 'unselect', preventDefault: true };
        case 37:
        case 65:
            return {
                action: 'select-previous',
                highlightedOnly: shiftKey,
                preventDefault: true,
            };
        case 39:
        case 68:
            return {
                action: 'select-next',
                highlightedOnly: shiftKey,
                preventDefault: true,
            };
        case 80:
            return { action: 'toggle-plain-text', preventDefault: true };
        default:
            return null;
    }
}
