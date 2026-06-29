/**
 * Text Reader 设置选项常量。
 *
 * 收敛 TextReader.vue 和 TextReaderSettings.vue 之间重复的选项数据。
 * 只包含纯展示层常量，不包含业务逻辑。
 */

/**
 * 最大文本宽度选项（px）。
 *
 * 索引 0-6 对应 slider 值 0-6。
 * - 索引 0: 800px（最窄）
 * - 索引 6: 100%（全宽）
 */
export const MAXIMUM_TEXT_WIDTH_OPTIONS = Object.freeze([
    '800px',
    '900px',
    '1000px',
    '1200px',
    '1400px',
    '1600px',
    '100%',
]);
