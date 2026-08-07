// PAB R2 Phase B reading-rating source contract guard.
// This guard is intentionally RED until Backend Core registers both reading sources.
// Behavioral DB coverage lives in ReadingReviewSourceUndoAnalyticsTest.php and is
// reserved for Lane 4's exclusive testing-DB run.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const reviewLogSource = readFileSync(join(root, 'app/Models/ReviewLog.php'), 'utf8');
const undoPolicySource = readFileSync(join(root, 'app/Services/SenseReviewUndoPolicy.php'), 'utf8');
const querySource = readFileSync(join(root, 'app/Services/SenseReviewQueryService.php'), 'utf8');

for (const source of ['reading_passive', 'reading_explicit']) {
    assert.match(
        reviewLogSource,
        new RegExp(`['\"]${source}['\"]`),
        `ReviewLog must define/register ${source} as a formal rating source`,
    );
    assert.match(
        undoPolicySource,
        new RegExp(`['\"]${source}['\"]`),
        `SenseReviewUndoPolicy must accept ${source} so an FSRS-changing reading rating remains undoable`,
    );
}

assert.match(
    reviewLogSource,
    /FORMAL_RATING_SOURCES/,
    'ReviewLog must keep a single formal-rating source registry',
);
assert.match(
    querySource,
    /ReviewLog::FORMAL_RATING_SOURCES/,
    'analytics query must consume the formal-rating source registry instead of copying a source list',
);
assert.match(
    querySource,
    /whereNull\(['\"](?:review_logs\.)?undone_at['\"]\)/,
    'product analytics must exclude undone reading ratings',
);

console.log('ReadingRatingSourceContractGuard passed.');
