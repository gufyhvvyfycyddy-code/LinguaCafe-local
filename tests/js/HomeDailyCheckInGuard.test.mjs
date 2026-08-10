import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(
    new URL('../../resources/js/components/Home/HomeDailyCheckIn.vue', import.meta.url),
    'utf8',
);

const getCalls = source.match(/axios\.get\s*\(/g) || [];
assert.equal(getCalls.length, 1, 'HomeDailyCheckIn must use exactly one GET data source.');
assert.match(
    source,
    /axios\.get\(\s*['"]\/home\/study-summary['"]\s*\)/,
    'HomeDailyCheckIn must use the single C-03 study-summary endpoint.',
);

for (const visibleMetric of [
    ['连续学习', /summary\.streak_days/],
    ['今日阅读', /summary\.today\.reading_completed_count/],
    ['今日复习', /summary\.today\.reviewed_count/],
]) {
    assert.ok(source.includes(visibleMetric[0]), `HomeDailyCheckIn must show ${visibleMetric[0]}.`);
    assert.match(source, visibleMetric[1], `${visibleMetric[0]} must bind to the server read model.`);
}

assert.match(
    source,
    /summary\.today\.checked_in\s*\?\s*['"]已打卡['"]\s*:\s*['"]未打卡['"]/,
    'Check-in status must come directly from today.checked_in.',
);
assert.match(
    source,
    /:to=["']summary\.continue_learning\.href["']/,
    'The primary CTA route must come directly from continue_learning.href.',
);
assert.ok(source.includes('继续学习'), 'The single primary CTA must be labelled 继续学习.');
assert.equal(
    (source.match(/color=["']primary["']/g) || []).length,
    1,
    'HomeDailyCheckIn must expose only one filled primary action.',
);

assert.match(
    source,
    /load\(\)\s*\{[\s\S]*?this\.state\s*=\s*['"]loading['"];[\s\S]*?this\.summary\s*=\s*null;[\s\S]*?axios\.get/,
    'Every load must clear stale success before requesting fresh data.',
);
assert.match(
    source,
    /\.catch\(\(\)\s*=>\s*\{[\s\S]*?this\.summary\s*=\s*null;[\s\S]*?this\.state\s*=\s*['"]error['"]/,
    'Error handling must clear stale success and enter the error state.',
);
assert.ok(source.includes('今日进度暂时无法加载'), 'The error state must show the frozen user-facing message.');
assert.ok(source.includes('重新加载'), 'The error state may expose one manual reload action.');

for (const forbidden of [
    '/study-overview/data',
    '/goals/get',
    'GoalAchievement',
    'localStorage',
    'sessionStorage',
]) {
    assert.ok(!source.includes(forbidden), `HomeDailyCheckIn must not contain forbidden source/path: ${forbidden}`);
}
assert.doesNotMatch(
    source,
    /due_count\s*\?[^:]+review|review_due_count\s*\?[^:]+review/i,
    'HomeDailyCheckIn must not recompute the continue-learning priority from due counts.',
);

console.log('Home Daily Check-In source guard passed; real browser acceptance remains a separate gate.');
