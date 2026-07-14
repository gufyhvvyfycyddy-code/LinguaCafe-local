import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(
    new URL('../../app/Services/StudyOverviewQueryService.php', import.meta.url),
    'utf8',
);

assert.match(
    source,
    /private\s+SenseReviewQueryService\s+\$senseReviewQueryService/,
    'StudyOverviewQueryService must inject the canonical SenseReviewQueryService.',
);
assert.match(
    source,
    /confirmedSenseCardQuery\s*\(/,
    'Study Overview workload must start from the confirmed sense-card query.',
);
assert.match(
    source,
    /->senseReviewEligible\s*\(/,
    'Study Overview workload must call the canonical ReviewCard::scopeSenseReviewEligible scope.',
);
assert.match(
    source,
    /whereIn\s*\(\s*['"]review_cards\.id['"]\s*,\s*\$cardIds\s*\)/,
    'Canonical eligibility must be intersected with the current Saved Search inventory.',
);
assert.doesNotMatch(
    source,
    /private\s+function\s+eligibleCards\s*\(/,
    'StudyOverviewQueryService must not own a duplicate eligibleCards implementation.',
);
assert.doesNotMatch(
    source,
    /return\s+\$cards->filter\s*\(\s*function\s*\(\s*\$card\s*\)\s+use\s*\(\s*\$now\s*\)/,
    'Workload eligibility must not be reimplemented as an in-memory lifecycle filter.',
);

console.log('Study Overview canonical eligibility architecture guard passed.');
