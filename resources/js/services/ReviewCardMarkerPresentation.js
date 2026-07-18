export const REVIEW_CARD_MARKERS = Object.freeze([
    { value: 0, label: '无标记', color: 'grey', icon: 'mdi-flag-outline' },
    { value: 1, label: '红色', color: 'red', icon: 'mdi-flag' },
    { value: 2, label: '橙色', color: 'orange', icon: 'mdi-flag' },
    { value: 3, label: '绿色', color: 'green', icon: 'mdi-flag' },
    { value: 4, label: '蓝色', color: 'blue', icon: 'mdi-flag' },
    { value: 5, label: '粉色', color: 'pink', icon: 'mdi-flag' },
    { value: 6, label: '青色', color: 'cyan', icon: 'mdi-flag' },
    { value: 7, label: '紫色', color: 'purple', icon: 'mdi-flag' },
].map(option => Object.freeze(option)));

export function markerOption(value) {
    return REVIEW_CARD_MARKERS.find(option => option.value === Number(value))
        || REVIEW_CARD_MARKERS[0];
}
