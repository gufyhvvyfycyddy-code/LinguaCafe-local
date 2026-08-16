import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const ui = read('mobile/src/ui.ts');
const queue = read('app/Services/MobileQueuedActionSyncService.php');
const controller = read('app/Http/Controllers/Mobile/MobileReadingSessionController.php');

assert.match(queue, /reading_session\.interaction/);
assert.match(queue, /MobileSenseReviewMutationService::idempotencyPayload/);
assert.match(queue, /\$hasReadingContext = \$action\['type'\] === self::TYPE_READING_INTERACTION/);
assert.match(queue, /if \(!\$hasReadingContext\) \{\s*throw \$exception;/);
assert.match(ui, /enqueueReadingInteraction/);
assert.match(ui, /enqueueRating/);
assert.match(ui, /finishReadingSession\(chapterId, session\.reading_session_id, 'preflight'\)/);
assert.match(ui, /queuedActions\(\)\)\.length > 0/);
assert.match(ui, /完成阅读需要联网/);
assert.doesNotMatch(ui, /FsrsSchedulingService|fsrs_due_at|recordReviewWithLog/);
assert.match(controller, /ReadingFinishSettlementService/);
assert.match(controller, /'settlement_mode' => \['required', 'in:preflight,commit'\]/);

console.log('E-04 mobile reading operations guard passed.');
