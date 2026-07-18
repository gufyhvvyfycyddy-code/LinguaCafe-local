import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const surfaceUrl = new URL('../../resources/js/components/Senses/SenseReviewSessionActionsSurface.vue', import.meta.url);
const parent = readFileSync(new URL('../../resources/js/components/Senses/SenseReview.vue', import.meta.url), 'utf8');
const identity = readFileSync(new URL('../../resources/js/components/Senses/SenseReviewSessionIdentity.js', import.meta.url), 'utf8');
const surface = existsSync(surfaceUrl) ? readFileSync(surfaceUrl, 'utf8') : '';

test('parent keeps session identity and embeds the actions surface', () => {
    assert.match(parent, /getOrCreateReviewSessionId/);
    assert.match(parent, /reviewSessionId\s*:/);
    assert.match(parent, /<SenseReviewSessionActionsSurface/);
    assert.match(parent, /ref="sessionActionsSurface"/);
    assert.match(parent, /v-model="sessionActionDrawerOpen"/);
    assert.match(parent, /:review-session-id="reviewSessionId"/);
    assert.match(parent, /@state-change="onSessionActionStateChange"/);
    assert.match(parent, /@undone="onSessionActionUndone"/);
    assert.doesNotMatch(parent, /sessionActions\s*:/);
    assert.doesNotMatch(parent, /sessionActionsLoading\s*:/);
    assert.doesNotMatch(parent, /sessionActionRequestSequence\s*:/);
    assert.doesNotMatch(parent, /\/reviews\/senses\/session-actions/);
});

test('surface owns timeline state, request races, and unchanged drawer affordances', () => {
    assert.ok(surface.length > 0, 'surface component must exist');
    assert.match(surface, /value:\s*Boolean/);
    assert.match(surface, /reviewSessionId:\s*String/);
    assert.match(surface, /actions:\s*\[\]/);
    assert.match(surface, /loading:\s*false/);
    assert.match(surface, /requestSequence:\s*0/);
    assert.match(surface, /reviewApi\.loadSenseSessionActions\(this\.reviewSessionId\)/);
    assert.match(surface, /seq !== this\.requestSequence/);
    assert.match(surface, /v-if="action\.undoable"/);
    assert.match(surface, /本次操作/);
    assert.match(surface, /本次复习还没有评分记录/);
    assert.match(surface, /this\.\$emit\('state-change'/);
});

test('rating retains backend session metadata and refreshes the surface', () => {
    assert.match(parent, /payload\.review_session_id = this\.reviewSessionId/);
    assert.match(parent, /response\.data\.action/);
    assert.match(parent, /this\.\$refs\.sessionActionsSurface.*reload\(\)/);
    assert.doesNotMatch(parent, /fake_review_log_id/);
});

test('session identity remains tab-scoped', () => {
    assert.doesNotMatch(identity, /localStorage\.(getItem|setItem|removeItem)/);
});
