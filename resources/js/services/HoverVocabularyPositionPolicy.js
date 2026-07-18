const BOX_WIDTH = 300;
const HORIZONTAL_MARGIN = 8;
const VERTICAL_GAP = 25;
const TOP_SPACE_BUFFER = 30;

export function resolveHoverVocabularyPosition({
    hoverBoxHeight,
    areaRect,
    areaScrollTop,
    wordRect,
    preferredPosition,
    correctionsEnabled,
}) {
    let positionLeft = wordRect.right - areaRect.left - BOX_WIDTH / 2
        - (wordRect.right - wordRect.left) / 2;
    const maximumPositionLeft = areaRect.right - areaRect.left - BOX_WIDTH - HORIZONTAL_MARGIN;

    if (positionLeft < HORIZONTAL_MARGIN) {
        positionLeft = HORIZONTAL_MARGIN;
    } else if (positionLeft > maximumPositionLeft) {
        positionLeft = maximumPositionLeft;
    }

    let arrowPosition = preferredPosition;
    const bottomPosition = wordRect.bottom - areaRect.top + areaScrollTop + VERTICAL_GAP;
    const bottomSpace = (areaRect.height + areaScrollTop) - bottomPosition;

    if (correctionsEnabled && arrowPosition === 'bottom' && bottomSpace < hoverBoxHeight) {
        arrowPosition = 'top';
    }

    const topSpace = wordRect.top - VERTICAL_GAP - TOP_SPACE_BUFFER;
    if (
        correctionsEnabled
        && arrowPosition === 'top'
        && topSpace < hoverBoxHeight
        && bottomSpace >= hoverBoxHeight
    ) {
        arrowPosition = 'bottom';
    }

    const positionTop = arrowPosition === 'top'
        ? wordRect.top - areaRect.top + areaScrollTop - hoverBoxHeight - VERTICAL_GAP
        : bottomPosition;

    return { positionLeft, positionTop, arrowPosition };
}
