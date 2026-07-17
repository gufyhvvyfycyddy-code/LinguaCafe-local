import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const sense = readFileSync(join(root, 'resources', 'js', 'components', 'Senses', 'SenseReview.vue'), 'utf8');
const legacy = readFileSync(join(root, 'resources', 'js', 'components', 'Review', 'Review.vue'), 'utf8');

assert.match(sense, /createRatingRequestCoordinator/);
assert.match(legacy, /createRatingRequestCoordinator/);
assert.doesNotMatch(sense, /ratingRequestSequence\s*:/);
assert.doesNotMatch(legacy, /ratingRequestSequence\s*:/);

assert.match(sense, /buildReviewCardManageLocation\(this\.currentCard, 'sense-review'\)/);
assert.match(sense, /openCardInfo\(\)/);
assert.match(sense, /case ['"]e['"]:[\s\S]*?this\.startEdit\(\)/);
assert.match(sense, /case ['"]i['"]:[\s\S]*?this\.openCardInfo\(\)/);
assert.match(sense, /case ['"]-['"]:[\s\S]*?this\.triggerLifecycleHotkey\('bury'\)/);
assert.match(sense, /case ['"]@['"]:[\s\S]*?this\.triggerLifecycleHotkey\('suspend'\)/);
assert.match(sense, /event\.(ctrlKey|metaKey)[\s\S]*?marker[\s\S]*?this\.setCurrentMarker\(nextMarker\)/);
assert.match(sense, /Number\(this\.currentCard\.marker \|\| 0\) === marker \? 0 : marker/);
assert.match(sense, /availableLifecycleActions\.includes\(action\)/);

assert.doesNotMatch(sense, /\/reviews\/senses\/[^\n]+\/delete/);

console.log('Sense Review reviewer convergence guard passed.');
