/**
 * SenseReviewReportCatalog
 *
 * SenseReview-ReportCatalog-1000-2 (consolidated in 1000-3 / ADR-0006)
 *
 * Single source of truth for the SenseReview report catalog metadata.
 * Pure configuration — no API calls, no Vuex, no state writes, no Vue
 * component instances. Consumed by SenseReviewReportCenter.vue to drive
 * the report home page, endpoint map, dialog width, loading text and
 * component mapping.
 *
 * Order is stable and intentional: daily-report → seven-day-trend →
 * thirty-day-calendar (today → short-term → long-term).
 *
 * The former "today-summary" entry was merged into "daily-report" in
 * ADR-0006. The catalog now has exactly three items.
 *
 * The `component` field is a string name used by ReportCenter to look up
 * its local imported-component map. Catalog itself never imports Vue
 * components, keeping it testable without a Vue runtime.
 */
export const REPORT_CATALOG = [
    {
        key: 'daily-report',
        title: '今日学习日报',
        description: '查看今天的复习概览、学习质量、重点词义、进步记录和最近复习。',
        icon: 'mdi-file-document-outline',
        color: 'primary',
        endpoint: '/reviews/senses/daily-report',
        component: 'SenseReviewDailyReport',
        payloadProp: 'report',
        maxWidth: 800,
        loadingText: '正在加载今日学习日报…',
    },
    {
        key: 'seven-day-trend',
        title: '近 7 天学习趋势',
        description: '查看最近一周每天的复习量与稳定情况。',
        icon: 'mdi-chart-line-variant',
        color: 'info',
        endpoint: '/reviews/senses/seven-day-trend',
        component: 'SenseReviewSevenDayTrend',
        payloadProp: 'trend',
        maxWidth: 820,
        loadingText: '正在加载近 7 天学习趋势…',
    },
    {
        key: 'thirty-day-calendar',
        title: '近 30 天复习日历',
        description: '按日期查看最近 30 天的复习次数和详细评分。',
        icon: 'mdi-calendar-month-outline',
        color: 'success',
        endpoint: '/reviews/senses/thirty-day-calendar',
        component: 'SenseReviewThirtyDayCalendar',
        payloadProp: 'calendar',
        maxWidth: 920,
        loadingText: '正在加载近 30 天复习日历…',
    },
];

/**
 * All valid report keys in stable order.
 */
export const REPORT_KEYS = REPORT_CATALOG.map((r) => r.key);

/**
 * Look up a report entry by key. Returns undefined for unknown keys.
 */
export function getReportByKey(key) {
    return REPORT_CATALOG.find((r) => r.key === key);
}

/**
 * Whether the given key is one of the catalog keys.
 */
export function isReportKey(key) {
    return REPORT_KEYS.includes(key);
}
