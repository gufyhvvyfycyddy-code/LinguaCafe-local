import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const page = readFileSync(
    new URL('../../resources/js/components/CustomStudy/CustomStudy.vue', import.meta.url),
    'utf8',
);
const session = readFileSync(
    new URL('../../resources/js/components/CustomStudy/SpecialStudySession.vue', import.meta.url),
    'utf8',
);
const legacySession = readFileSync(
    new URL('../../resources/js/components/CustomStudy/CustomStudySession.vue', import.meta.url),
    'utf8',
);
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

for (const scenario of ['today_forgotten', 'backlog', 'review_ahead', 'recent_new', 'filtered']) {
    assert.match(page, new RegExp(scenario));
}
for (const sort of ['most_overdue', 'most_lapses', 'lowest_retrievability', 'random', 'source']) {
    assert.match(page, new RegExp(sort));
}
for (const filter of ['tag_ids', 'markers', 'article_ids', 'chapter_ids', 'lifecycle_states', 'fsrs_states']) {
    assert.match(page, new RegExp(filter));
}

assert.match(page, /\/special-study\/options/);
assert.match(page, /\/special-study\/sessions/);
assert.match(page, /\/reviews\/senses\/today-limits/);
assert.match(page, /最近新建词义/);
assert.match(page, /预览（不影响排程）/);
assert.match(page, /提前正式复习（会重新排程）/);
assert.match(page, /正式评分（写入 ReviewLog 与 FSRS）/);
assert.match(page, /不移动卡片/);
assert.doesNotMatch(page, /localStorage/);
assert.doesNotMatch(page, /\$store/);

assert.match(session, /client_action_id/);
assert.match(session, /expected_revision/);
assert.match(session, /review_duration_ms/);
assert.match(session, /\/answer/);
assert.match(session, /\/save/);
assert.match(session, /\/rebuild/);
assert.match(session, /\/end/);
assert.match(session, /预览模式：回答只推进本次会话/);
assert.match(session, /正式评分：回答会写入 ReviewLog、FSRS 与撤销账本/);

// The old encrypted-token component remains available for route/API
// compatibility even though the page now uses the M12 aggregate.
assert.match(legacySession, /\/custom-study\/sessions\/answer/);
assert.match(legacySession, /\/custom-study\/sessions\/resume/);

assert.match(app, /path: '\/custom-study', component: CustomStudy/);

console.log('CustomStudy page guard passed.');
