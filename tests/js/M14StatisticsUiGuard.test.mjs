import assert from 'node:assert/strict';
import fs from 'node:fs';

const statistics = fs.readFileSync('resources/js/components/Home/Statistics.vue', 'utf8');
const chart = fs.readFileSync('resources/js/components/Home/StatisticsMiniChart.vue', 'utf8');

assert.match(statistics, /axios\.post\('\/statistics\/get'/);
assert.match(statistics, /statistics\/export\/\$\{format\}/);
assert.match(statistics, /period_days/);
assert.match(statistics, /按统一查询缩小范围/);
assert.match(statistics, /真实保持率/);
assert.match(statistics, /学习日历/);
assert.match(statistics, /@media \(max-width: 700px\)/);
assert.doesNotMatch(statistics, /ReviewLog|fsrs_due_at|review_duration_ms/);
assert.match(chart, /barWidth/);

console.log('M14 statistics UI guard passed: server metrics, unified scope, exports and responsive charts are wired.');
