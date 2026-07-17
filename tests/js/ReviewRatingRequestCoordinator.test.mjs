import assert from 'node:assert/strict';
import {
    _resetForTest,
    classifyRatingRequestError,
    createRatingRequestCoordinator,
    ratingRecoveryMessage,
} from '../../resources/js/components/Review/ReviewRatingRecovery.js';

assert.equal(classifyRatingRequestError({}), 'network');
assert.equal(classifyRatingRequestError({ response: { status: 401 } }), 'authentication');
assert.equal(classifyRatingRequestError({ response: { status: 409 } }), 'conflict');
assert.equal(classifyRatingRequestError({ response: { status: 422 } }), 'validation');
assert.equal(classifyRatingRequestError({ response: { status: 500 } }), 'server');
assert.match(ratingRecoveryMessage('conflict', '词义复习'), /评分请求未被确认/);
assert.match(ratingRecoveryMessage('authentication', '词义复习'), /登录状态已失效/);
assert.match(ratingRecoveryMessage('network', '词义复习'), /评分结果状态不确定/);

const locks = [];
const messages = [];
let reloads = 0;
let loadError = '';
const coordinator = createRatingRequestCoordinator({
    setLocked: value => locks.push(value),
    reloadQueue: () => { reloads++; return Promise.resolve(); },
    hasLoadError: () => Boolean(loadError),
    setRecoveryMessage: kind => messages.push(kind),
});

const first = coordinator.begin();
assert.equal(first, 1);
assert.equal(coordinator.begin(), null, 'a second rating is blocked while locked');
assert.equal(coordinator.isCurrent(first), true);
assert.deepEqual(locks, [true]);

coordinator.invalidate();
assert.equal(coordinator.isCurrent(first), false);
assert.deepEqual(locks, [true, false]);

const second = coordinator.begin();
assert.equal(coordinator.succeed(second), true);
assert.deepEqual(locks, [true, false, true, false]);
assert.equal(coordinator.succeed(second), false, 'a completed request cannot unlock twice');

_resetForTest();
const third = coordinator.begin();
const recovery = coordinator.recover(third, { response: { status: 409 } });
assert.equal(coordinator.begin(), null, 'recovery keeps the coordinator locked');
await recovery;
assert.equal(reloads, 1);
assert.deepEqual(messages, ['conflict']);
assert.equal(coordinator.begin(), 5, 'rating unlocks after authoritative reload');

const stale = coordinator.recover(third, {});
assert.equal(await stale, 'stale');
assert.equal(reloads, 1, 'stale failure never reloads the queue');

loadError = 'load failed';
const current = coordinator.current();
await coordinator.recover(current, {});
assert.deepEqual(messages, ['conflict'], 'load errors take precedence over recovery messages');

console.log('Review rating request coordinator passed.');
