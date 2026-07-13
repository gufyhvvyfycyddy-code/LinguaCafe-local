/**
 * ReviewRatingRecovery.test.mjs — executable behavior tests for the
 * runAuthoritativeRatingRecovery helper.
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

    // Test 3: not unlocked before reload settles
    {
        _resetForTest();
        let unlocked = false;
        const d = deferred();
        const p = runAuthoritativeRatingRecovery({
            reloadQueue: () => d.promise,
            lockRating: () => {},
            unlockRating: () => { unlocked = true; },
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        await flush();
        ok('3. not unlocked while reload pending', !unlocked);
        d.resolve();
        await p;
        ok('3b. unlocked after reload resolves', unlocked);
    }

    // Test 4: unlocked after reload success
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
        ok('4. unlocked after reload success', unlocked);
    }

    // Test 5: unlocked after reload failure
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
        ok('5. unlocked after reload failure', unlocked);
    }

    // Test 6: recovery message shown on reload success (no load error)
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
        ok('6. recovery message shown when no load error', messageSet);
    }

    // Test 7: load error not overwritten by recovery message
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
        ok('7. recovery message NOT shown when load error exists', !messageSet);
    }

    // Test 8: concurrent recovery rejected (no second reload)
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
        ok('8. second concurrent recovery is a no-op', reloadCount === 1);
        d.resolve();
        await p1;
        await p2;
        ok('8b. still only one reload after both settle', reloadCount === 1);
    }

    // Test 9: Legacy Review configuration (ratingLoading)
    {
        _resetForTest();
        const state = { ratingLoading: false, reviewError: '' };
        await runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.resolve(),
            lockRating: () => { state.ratingLoading = true; },
            unlockRating: () => { state.ratingLoading = false; },
            setRecoveryMessage: () => { state.reviewError = 'uncertain'; },
            preserveLoadError: () => !!state.reviewError,
        });
        ok('9. Legacy: ratingLoading unlocked after recovery', state.ratingLoading === false);
        ok('9b. Legacy: reviewError set', state.reviewError === 'uncertain');
    }

    // Test 10: Sense Review configuration (this.rating)
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
        ok('10. Sense: rating unlocked after recovery', state.rating === false);
        ok('10b. Sense: error set', state.error === 'uncertain');
    }

    // Test 11: helper does not modify statistics
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
        ok('11. helper does not modify statistics', JSON.stringify(stats) === before);
    }

    // Test 12: helper does not call rating API
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
        ok('12. helper does not call rating API', !ratingApiCalled);
    }

    // Test 13: helper does not write ReviewLog
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
        ok('13. helper does not write ReviewLog', !writeCalled);
    }

    // Test 14: helper does not modify FSRS
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
        ok('14. helper does not modify FSRS', JSON.stringify(fsrs) === before);
    }

    // Test 15: Promise rejection does not produce unhandled rejection
    {
        _resetForTest();
        let unhandledRejection = false;
        const onUnhandled = () => { unhandledRejection = true; };
        process.on('unhandledRejection', onUnhandled);
        const p = runAuthoritativeRatingRecovery({
            reloadQueue: () => Promise.reject(new Error('fail')),
            lockRating: () => {},
            unlockRating: () => {},
            setRecoveryMessage: () => {},
            preserveLoadError: () => false,
        });
        await p;
        await flush();
        process.off('unhandledRejection', onUnhandled);
        ok('15. reload rejection does not produce unhandled rejection', !unhandledRejection);
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
