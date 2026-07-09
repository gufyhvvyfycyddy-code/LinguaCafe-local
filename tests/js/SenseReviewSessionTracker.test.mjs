// SenseReviewSessionTracker.test.mjs
//
// SenseReview-SessionSummary-1000-1
//
// Node built-in assert tests for the pure-JS session tracker that powers
// the SenseReview "本次复习总结" feature. No third-party test framework
// is required — this runs with `node --test` or plain `node <file>`.
//
// Contract:
//   - A session tracks only ratings that happened AFTER the page was opened.
//   - Each rating is recorded exactly once (double-click / double-response
//     safe via requestId dedup).
//   - "需要重点注意" (needs attention) is factual only:
//       rating === 'again' OR rating === 'hard' OR
//       forgetting_pattern.trend === 'declining'
//   - No AI, no persistence, no network.
//   - Reset produces a fresh empty session.

import assert from 'node:assert/strict';
import {
    createSession,
    recordRating,
    sessionStats,
    hasReviewed,
    resetSession,
} from '../../resources/js/components/Senses/SenseReviewSessionTracker.js';

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (e) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
        process.exitCode = 1;
    }
}

console.log('SenseReviewSessionTracker tests\n');

// 1. 新会话统计为 0
test('new session has zero stats', () => {
    const s = createSession();
    const stats = sessionStats(s);
    assert.equal(stats.total, 0);
    assert.equal(stats.again, 0);
    assert.equal(stats.hard, 0);
    assert.equal(stats.good, 0);
    assert.equal(stats.easy, 0);
    assert.equal(stats.needsAttention.length, 0);
});

// 2. 四种 rating 计数正确
test('four rating types are counted correctly', () => {
    let s = createSession();
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'again', 'stable'), 'r1');
    s = recordRating(s, makeEntry('card-2', 'river', '河', 'hard', 'stable'), 'r2');
    s = recordRating(s, makeEntry('card-3', 'book', '书', 'good', 'stable'), 'r3');
    s = recordRating(s, makeEntry('card-4', 'pen', '笔', 'easy', 'stable'), 'r4');
    const stats = sessionStats(s);
    assert.equal(stats.total, 4);
    assert.equal(stats.again, 1);
    assert.equal(stats.hard, 1);
    assert.equal(stats.good, 1);
    assert.equal(stats.easy, 1);
});

// 3. 同一评分响应只记录一次（防止双击重复）
test('same requestId is recorded only once (double-click safe)', () => {
    let s = createSession();
    const entry = makeEntry('card-1', 'bank', '银行', 'again', 'stable');
    s = recordRating(s, entry, 'req-abc');
    s = recordRating(s, entry, 'req-abc'); // same requestId — ignored
    s = recordRating(s, entry, 'req-abc'); // same requestId — ignored
    const stats = sessionStats(s);
    assert.equal(stats.total, 1);
    assert.equal(stats.again, 1);
});

// 4. again / hard 进入重点词义
test('again and hard enter needsAttention', () => {
    let s = createSession();
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'again', 'stable'), 'r1');
    s = recordRating(s, makeEntry('card-2', 'river', '河', 'hard', 'stable'), 'r2');
    const stats = sessionStats(s);
    assert.equal(stats.needsAttention.length, 2);
    assert.ok(stats.needsAttention.find(e => e.review_card_id === 'card-1'));
    assert.ok(stats.needsAttention.find(e => e.review_card_id === 'card-2'));
});

// 5. declining 进入重点词义
test('declining trend enters needsAttention even when rating is good', () => {
    let s = createSession();
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'good', 'declining'), 'r1');
    const stats = sessionStats(s);
    assert.equal(stats.needsAttention.length, 1);
    assert.equal(stats.needsAttention[0].review_card_id, 'card-1');
    assert.equal(stats.needsAttention[0].trend, 'declining');
});

// 6. good / easy 且趋势不下降时不进入重点词义
test('good/easy with non-declining trend does NOT enter needsAttention', () => {
    let s = createSession();
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'good', 'stable'), 'r1');
    s = recordRating(s, makeEntry('card-2', 'river', '河', 'easy', 'improving'), 'r2');
    s = recordRating(s, makeEntry('card-3', 'book', '书', 'good', 'insufficient'), 'r3');
    const stats = sessionStats(s);
    assert.equal(stats.needsAttention.length, 0);
});

// 7. 总结只统计当前会话
test('stats only reflect current session entries', () => {
    let s1 = createSession();
    s1 = recordRating(s1, makeEntry('card-1', 'bank', '银行', 'again', 'stable'), 'r1');
    s1 = recordRating(s1, makeEntry('card-2', 'river', '河', 'good', 'stable'), 'r2');

    // A completely separate session should not see s1's entries
    let s2 = createSession();
    s2 = recordRating(s2, makeEntry('card-9', 'sky', '天空', 'easy', 'stable'), 'r9');
    const stats2 = sessionStats(s2);
    assert.equal(stats2.total, 1);
    assert.equal(stats2.easy, 1);
    assert.equal(stats2.again, 0);

    // s1 is unchanged
    const stats1 = sessionStats(s1);
    assert.equal(stats1.total, 2);
});

// 8. 清空会话后重新计数
test('resetSession produces a fresh empty session', () => {
    let s = createSession();
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'again', 'stable'), 'r1');
    s = recordRating(s, makeEntry('card-2', 'river', '河', 'good', 'stable'), 'r2');
    assert.equal(sessionStats(s).total, 2);

    s = resetSession(s);
    assert.equal(sessionStats(s).total, 0);
    assert.equal(hasReviewed(s), false);

    // Can record again after reset
    s = recordRating(s, makeEntry('card-3', 'book', '书', 'good', 'stable'), 'r3');
    assert.equal(sessionStats(s).total, 1);
});

// 9. 未评分时不进入总结状态
test('hasReviewed returns false for empty session', () => {
    const s = createSession();
    assert.equal(hasReviewed(s), false);
    const s2 = recordRating(s, makeEntry('card-1', 'bank', '银行', 'good', 'stable'), 'r1');
    assert.equal(hasReviewed(s2), true);
});

// 10. 总结状态下快捷键不会继续评分（tracker 层面的不变量：
//     recordRating 需要 requestId，容器在 summary 状态下不会调用它。
//     这里测试 session 本身不会被没有 requestId 的调用污染）
test('recording without requestId is rejected (prevents accidental pollution)', () => {
    let s = createSession();
    // requestId undefined / null / empty-string are all invalid
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'good', 'stable'), undefined);
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'good', 'stable'), null);
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'good', 'stable'), '');
    assert.equal(sessionStats(s).total, 0);
    assert.equal(hasReviewed(s), false);
});

// Extra: needsAttention entry shape
test('needsAttention entries contain lemma, sense_zh, rating, trend', () => {
    let s = createSession();
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'again', 'declining'), 'r1');
    const stats = sessionStats(s);
    assert.equal(stats.needsAttention.length, 1);
    const item = stats.needsAttention[0];
    assert.equal(item.lemma, 'bank');
    assert.equal(item.sense_zh, '银行');
    assert.equal(item.rating, 'again');
    assert.equal(item.trend, 'declining');
    assert.equal(item.review_card_id, 'card-1');
});

// Extra: multiple ratings on the same card (after reset+re-due) are tracked separately
test('same card rated twice with different requestIds is tracked as two entries', () => {
    let s = createSession();
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'again', 'stable'), 'r1');
    s = recordRating(s, makeEntry('card-1', 'bank', '银行', 'good', 'improving'), 'r2');
    const stats = sessionStats(s);
    assert.equal(stats.total, 2);
    assert.equal(stats.again, 1);
    assert.equal(stats.good, 1);
    // Only the 'again' one is in needsAttention
    assert.equal(stats.needsAttention.length, 1);
});

console.log(`\n${passed} passed`);
console.log('Done.');

// === Helper ===
function makeEntry(reviewCardId, lemma, senseZh, rating, trend) {
    return {
        review_card_id: reviewCardId,
        lemma: lemma,
        sense_zh: senseZh,
        rating: rating,
        forgetting_pattern: { trend: trend },
    };
}
