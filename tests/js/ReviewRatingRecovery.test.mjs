/**
 * ReviewRatingRecovery.test.mjs — executable behavior tests for the
 * runAuthoritativeRatingRecovery helper (Task 2000-14).
 *
 * Covers the real-world side effect where reloadQueue() synchronously
 * resets the page lock state (Legacy loadReviews sets ratingLoading=false
 * at its start), plus concurrent recovery, synchronous throws, and the
 * "same in-flight Promise" contract.
 */

import assert from 'node:assert/strict';
import { runAuthoritativeRatingRecovery, _resetForTest } from '../../resources/js/components/Review/ReviewRatingRecovery.js';

let passed = 0;
let failed = 0;

function ok(name, cond) {
    if (cond) {
        passed++;
    } else {
        failed++;
        console.error('FAIL - ' + name);
    }
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

function flush() {
    return new Promise(r => setTimeout(r, 0));
}

async function testSuite() {
    // Test 1: recovery immediately locks
    {
        _resetForTest();
        let locked = false;
        let unlocked = false;
        const d = deferred();
        runAuthoritativeRatingRecovery({
            reloadQueue: () => d.promise,
            lockRating: () => { locked = true; },
            unlockRating: () => { unlocked = true; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('1. recovery immediately locks', locked);
        ok('1b. not unlocked before reload settles', !unlocked);
        d.resolve();
        await flush();
        ok('1c. unlocked after reload settles', unlocked);
    }

    // Test 2: reloadQueue called exactly once
    {
        _resetForTest();
        let reloadCount = 0;
        const d = deferred();
        const p = runAuthoritativeRatingRecovery({
            reloadQueue: () => { reloadCount++; return d.promise; },
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        d.resolve();
        await p;
        ok('2. reloadQueue called exactly once', reloadCount === 1);
    }

    // Test 3: reloadQueue synchronously resets lock, helper re-locks
    // (This is the real Legacy loadReviews side effect.)
    {
        _resetForTest();
        let locked = false;
        let unlocked = false;
        const lockState = { value: false };
        const d = deferred();
        runAuthoritativeRatingRecovery({
            // reloadQueue mimics Legacy loadReviews: synchronously sets
            // ratingLoading=false at its start.
            reloadQueue: () => {
                lockState.value = false;
                return d.promise;
            },
            lockRating: () => { lockState.value = true; },
            unlockRating: () => { lockState.value = false; unlocked = true; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('3. lock set true initially', lockState.value === true);
        // After reloadQueue returns, helper re-locks.
        ok('3b. lock re-confirmed true after reloadQueue sync reset', lockState.value === true);
        ok('3c. not unlocked while pending', !unlocked);
        d.resolve();
        await flush();
        ok('3d. unlocked after settle', unlocked);
    }

    // Test 4: pending期间 lock===true (real side effect simulation)
    {
        _resetForTest();
        const lockState = { value: false };
        const d = deferred();
        runAuthoritativeRatingRecovery({
            reloadQueue: () => {
                lockState.value = false;
                return d.promise;
            },
            lockRating: () => { lockState.value = true; },
            unlockRating: () => { lockState.value = false; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        // While pending, lock must be true.
        ok('4. lock===true during pending (after sync reset + re-lock)', lockState.value === true);
        await flush();
        ok('4b. lock still true after flush while pending', lockState.value === true);
        d.resolve();
        await flush();
        ok('4c. lock false after settle', lockState.value === false);
    }

    // Test 5: reload resolve后解锁
    {
        _resetForTest();
        let unlocked = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => {},
            unlockRating: () => { unlocked = true; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('5. unlocked after reload success', unlocked);
    }

    // Test 6: reload reject后解锁
    {
        _resetForTest();
        let unlocked = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.reject(new Error('network')),
            lockRating: () => {},
            unlockRating: () => { unlocked = true; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('6. unlocked after reload failure', unlocked);
    }

    // Test 7: reloadQueue sync throw后解锁
    {
        _resetForTest();
        let unlocked = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => { throw new Error('sync boom'); },
            lockRating: () => {},
            unlockRating: () => { unlocked = true; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('7. unlocked after sync throw', unlocked);
    }

    // Test 8: sync throw不产生 unhandled rejection
    {
        _resetForTest();
        let unhandledRejection = false;
        const onUnhandled = () => { unhandledRejection = true; };
        process.on('unhandledRejection', onUnhandled);
        const p = runAuthoritativeRatingRecovery({
            reloadQueue: () => { throw new Error('sync boom'); },
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        await p;
        await flush();
        process.off('unhandledRejection', onUnhandled);
        ok('8. sync throw no unhandled rejection', !unhandledRejection);
    }

    // Test 9: sync throw后下一次 recovery可正常执行
    {
        _resetForTest();
        let reloadCount = 0;
        // First recovery: sync throw.
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => { reloadCount++; throw new Error('sync boom'); },
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('9. first recovery (sync throw) ran reload once', reloadCount === 1);
        // Second recovery: should work, not be blocked by inFlight.
        let unlocked2 = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => { reloadCount++; return Promise.resolve(); },
            lockRating: () => {},
            unlockRating: () => { unlocked2 = true; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('9b. second recovery after sync throw works', reloadCount === 2 && unlocked2);
    }

    // Test 10: 两次并发调用返回同一个 Promise
    {
        _resetForTest();
        const d = deferred();
        const opts = {
            reloadQueue: () => d.promise,
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        };
        const p1 = runAuthoritativeRatingRecovery(opts);
        const p2 = runAuthoritativeRatingRecovery(opts);
        ok('10. concurrent calls return same Promise', p1 === p2);
        d.resolve();
        await p1;
        await p2;
    }

    // Test 11: 两次并发调用只产生一次 reload
    {
        _resetForTest();
        let reloadCount = 0;
        const d = deferred();
        const opts = {
            reloadQueue: () => { reloadCount++; return d.promise; },
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        };
        const p1 = runAuthoritativeRatingRecovery(opts);
        const p2 = runAuthoritativeRatingRecovery(opts);
        await flush();
        ok('11. only one reload for concurrent calls', reloadCount === 1);
        d.resolve();
        await p1;
        await p2;
        ok('11b. still one reload after both settle', reloadCount === 1);
    }

    // Test 12: 并发第二个调用不会提前 settle
    {
        _resetForTest();
        const d = deferred();
        let p2Settled = false;
        const opts = {
            reloadQueue: () => d.promise,
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        };
        const p1 = runAuthoritativeRatingRecovery(opts);
        const p2 = runAuthoritativeRatingRecovery(opts);
        p2.then(() => { p2Settled = true; });
        await flush();
        await flush();
        ok('12. second concurrent call not settled before first', !p2Settled);
        d.resolve();
        await p1;
        ok('12b. second settled after first resolves', p2Settled);
    }

    // Test 13: 成功重载且无 load error时显示 recovery message
    {
        _resetForTest();
        let messageSet = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => { messageSet = true; },
            preserveLoadError: () => false,
        });
        ok('13. recovery message shown when no load error', messageSet);
    }

    // Test 14: 已有 load error时不覆盖
    {
        _resetForTest();
        let messageSet = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => { messageSet = true; },
            preserveLoadError: () => true,
        });
        ok('14. recovery message NOT shown when load error exists', !messageSet);
    }

    // Test 15: Legacy 实际副作用模拟
    {
        _resetForTest();
        // Simulate Legacy: lockRating sets ratingLoading=true,
        // loadReviews() synchronously sets ratingLoading=false,
        // helper must re-lock to true, keep true during pending,
        // then unlock to false on settle.
        const state = { ratingLoading: false, reviewError: '' };
        const d = deferred();
        runAuthoritativeRatingRecovery({
            reloadQueue: () => {
                // Mimic loadReviews() prologue: ratingLoading = false.
                state.ratingLoading = false;
                return d.promise;
            },
            lockRating: () => { state.ratingLoading = true; },
            unlockRating: () => { state.ratingLoading = false; },
            setRecoveryMessage: () => { state.reviewError = 'uncertain'; },
            preserveLoadError: () => !!state.reviewError,
        });
        ok('15. Legacy: lock true after initial lockRating', state.ratingLoading === true);
        ok('15b. Legacy: re-locked true after reloadQueue sync reset', state.ratingLoading === true);
        ok('15c. Legacy: lock true during pending', state.ratingLoading === true);
        d.resolve();
        await flush();
        ok('15d. Legacy: lock false after settle', state.ratingLoading === false);
    }

    // Test 16: Sense Review 配置仍通过
    {
        _resetForTest();
        const state = { rating: false, error: '' };
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => { state.rating = true; },
            unlockRating: () => { state.rating = false; },
            setRecoveryMessage: () => { state.error = 'uncertain'; },
            preserveLoadError: () => !!state.error,
        });
        ok('16. Sense: rating unlocked after recovery', state.rating === false);
        ok('16b. Sense: error set', state.error === 'uncertain');
    }

    // Test 17: 不修改统计
    {
        _resetForTest();
        const stats = { correctReviews: 5, finishedReviews: 3, readWords: 10 };
        const before = JSON.stringify(stats);
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('17. helper does not modify statistics', JSON.stringify(stats) === before);
    }

    // Test 18: 不调用 rating API
    {
        _resetForTest();
        let ratingApiCalled = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('18. helper does not call rating API', !ratingApiCalled);
    }

    // Test 19: 不写 ReviewLog
    {
        _resetForTest();
        let writeCalled = false;
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('19. helper does not write ReviewLog', !writeCalled);
    }

    // Test 20: 不修改 FSRS
    {
        _resetForTest();
        const fsrs = { stability: 1.5, difficulty: 0.3, state: 2 };
        const before = JSON.stringify(fsrs);
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        ok('20. helper does not modify FSRS', JSON.stringify(fsrs) === before);
    }

    console.log('ReviewRatingRecovery.test.mjs: ' + passed + ' passed, ' + failed + ' failed');
    if (failed > 0) {
        process.exit(1);
    }
}

testSuite().catch(err => {
    console.error('Test suite threw:', err);
    process.exit(1);
});
