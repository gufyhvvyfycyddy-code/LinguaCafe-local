import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const adrPath = 'docs/adr/ADR-0029-card-marker-and-custom-study-1b.md';

test('card marker and marked custom study contract is frozen', () => {
    assert.equal(fs.existsSync(adrPath), true, 'ADR-0029 must exist');

    const adr = fs.readFileSync(adrPath, 'utf8');
    assert.match(adr, /review_cards\.marker/);
    assert.match(adr, /0[^\n]+7/);
    assert.match(adr, /review_cards_marker_range_check/);
    assert.match(adr, /user_id[^\n]+language_id[^\n]+target_type[^\n]+marker/);
    assert.match(adr, /PATCH \/review-cards\/manage\/\{reviewCard\}\/marker/);
    assert.match(adr, /POST \/review-cards\/manage\/bulk-marker/);
    assert.match(adr, /mode.?=.?marked/);
    assert.match(adr, /preview/i);
    assert.match(adr, /ReviewLog/);
    assert.match(adr, /FSRS/);
});
