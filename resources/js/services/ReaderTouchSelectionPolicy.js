export const READER_TOUCH_LONG_PRESS_MS = 450;
export const READER_TOUCH_MOVE_TOLERANCE_PX = 10;

export function createReaderTouchSelectionGesture({
    wordIndex,
    clientX,
    clientY,
}) {
    return {
        wordIndex: Number(wordIndex),
        startX: Number(clientX),
        startY: Number(clientY),
        moved: false,
        longPressActivated: false,
    };
}

export function updateReaderTouchSelectionGesture(
    gesture,
    {
        clientX,
        clientY,
        moveTolerance = READER_TOUCH_MOVE_TOLERANCE_PX,
    },
) {
    if (!gesture) {
        return null;
    }

    const deltaX = Number(clientX) - gesture.startX;
    const deltaY = Number(clientY) - gesture.startY;
    const moved = gesture.moved
        || Math.hypot(deltaX, deltaY) > moveTolerance;

    return {
        ...gesture,
        moved,
    };
}

export function activateReaderTouchLongPress(gesture) {
    if (!gesture || gesture.moved) {
        return gesture;
    }

    return {
        ...gesture,
        longPressActivated: true,
    };
}

export function resolveReaderTouchEndAction(gesture) {
    if (!gesture) {
        return 'cancel';
    }
    if (gesture.longPressActivated) {
        return 'finish';
    }
    if (!gesture.moved) {
        return 'tap';
    }

    return 'cancel';
}
