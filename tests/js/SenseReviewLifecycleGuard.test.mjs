import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const review = readFileSync(join(root, 'resources', 'js', 'components', 'Senses', 'SenseReview.vue'), 'utf8');
const manage = readFileSync(join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardManage.vue'), 'utf8');
const lifecycleSurface = readFileSync(join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardLifecycleMutationSurface.vue'), 'utf8');
const schedulingSurface = readFileSync(join(root, 'resources', 'js', 'components', 'ReviewCards', 'ReviewCardSchedulingMutationSurface.vue'), 'utf8');

assert.match(review, /ReviewCardLifecyclePresentation/);
assert.match(review, /lifecycleDescriptor/);
assert.match(review, /currentCardLifecycleState/);
assert.match(review, /currentCardIsInactive/);
assert.match(review, /buriedRemainingDisplay/);
assert.match(review, /fetchLifecycleDescriptor/);
assert.match(review, /axios\.get\(`\/review-cards\/\$\{this\.currentCard\.review_card_id\}\/lifecycle`\)/);
assert.match(review, /stateLabel\(currentCardLifecycleState\)/);

assert.doesNotMatch(review, /availableLifecycleActions/);
assert.doesNotMatch(review, /lifecycleDialog|lifecycleLoading|lifecycleConflict/);
assert.doesNotMatch(review, /onLifecycleMenuClick|openLifecycleDialog|executeLifecycleAction|performLifecycleAction/);
assert.doesNotMatch(review, /\/lifecycle-actions/);
assert.doesNotMatch(review, /manual-operations\/(?:preview|apply)/);
assert.doesNotMatch(review, /ReviewCardSchedulingMutationSurface/);

assert.match(manage, /ReviewCardLifecycleMutationSurface/);
assert.match(manage, /ReviewCardSchedulingMutationSurface/);
assert.match(lifecycleSurface, /\/manual-operations\/preview/);
assert.match(lifecycleSurface, /\/manual-operations\/apply/);
assert.match(lifecycleSurface, /\/review-cards\/manage\/bulk-lifecycle/);
assert.match(schedulingSurface, /\/manual-operations\/preview/);
assert.match(schedulingSurface, /\/manual-operations\/apply/);

console.log('SenseReview lifecycle placement guard passed.');
