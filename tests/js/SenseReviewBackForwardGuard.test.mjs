import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync('resources/js/components/Senses/SenseReview.vue', 'utf8');
const bar = readFileSync('resources/js/components/Senses/SenseReviewExperienceBar.vue', 'utf8');
const controller = readFileSync('resources/js/components/Senses/SenseReviewExperienceController.vue', 'utf8');
const surface = readFileSync('resources/js/components/Senses/SenseReviewSessionActionsSurface.vue', 'utf8');
const history = readFileSync('resources/js/components/Senses/SenseReviewNavigationHistory.js', 'utf8');
const backendController = readFileSync('app/Http/Controllers/SenseReviewController.php', 'utf8');

test('visible back and forward buttons are wired through the controller into the page navigation methods', () => {
    assert.match(bar, /<v-btn[^>]*:disabled="!previousAvailable \|\| busy"[^>]*@click="\$emit\('previous-card'\)"[^>]*>[\s\S]*?返回[\s\S]*?<\/v-btn>/);
    assert.match(bar, /<v-btn[^>]*:disabled="!forwardAvailable \|\| busy"[^>]*@click="\$emit\('next-card'\)"[^>]*>[\s\S]*?前进[\s\S]*?<\/v-btn>/);

    const controllerBarStart = controller.indexOf('<SenseReviewExperienceBar');
    const controllerBarEnd = controller.indexOf('>', controllerBarStart);
    assert.ok(controllerBarStart >= 0 && controllerBarEnd > controllerBarStart);
    const controllerBar = controller.slice(controllerBarStart, controllerBarEnd + 1);
    assert.match(controllerBar, /:previous-available="previousAvailable"/);
    assert.match(controllerBar, /:forward-available="forwardAvailable"/);
    assert.match(controllerBar, /@previous-card="\$emit\('previous-card'\)"/);
    assert.match(controllerBar, /@next-card="\$emit\('next-card'\)"/);

    const pageControllerStart = page.indexOf('<SenseReviewExperienceController');
    const pageControllerEnd = page.indexOf('/>', pageControllerStart);
    assert.ok(pageControllerStart >= 0 && pageControllerEnd > pageControllerStart);
    const pageController = page.slice(pageControllerStart, pageControllerEnd + 2);
    assert.match(pageController, /:previous-available="previousNavigationAvailable"/);
    assert.match(pageController, /:forward-available="forwardNavigationAvailable"/);
    assert.match(pageController, /@previous-card="goPreviousCard\('sense_review_history'\)"/);
    assert.match(pageController, /@next-card="goForwardCard"/);
});

test('refresh restores navigation only after the current review session id is known and puts that card first', () => {
    const mountedStart = page.indexOf('mounted() {');
    const methodsStart = page.indexOf('methods:', mountedStart);
    assert.ok(mountedStart >= 0 && methodsStart > mountedStart);
    const mounted = page.slice(mountedStart, methodsStart);
    const sessionIdIndex = mounted.indexOf('this.reviewSessionId = getOrCreateReviewSessionId();');
    const historyIndex = mounted.indexOf('this.navigationHistory = loadReviewNavigationHistory(this.reviewSessionId);');
    const loadCardsIndex = mounted.indexOf('this.loadCards();');
    assert.ok(sessionIdIndex >= 0 && historyIndex > sessionIdIndex && loadCardsIndex > historyIndex);

    const loadCardsStart = page.indexOf('loadCards() {', methodsStart);
    const loadCardsEnd = page.indexOf('loadIntervalPreview()', loadCardsStart);
    assert.ok(loadCardsStart >= 0 && loadCardsEnd > loadCardsStart);
    const loadCards = page.slice(loadCardsStart, loadCardsEnd);
    assert.match(loadCards, /preferredCardId = this\.navigationHistory\.currentCardId/);
    assert.match(loadCards, /preferredIndex = cards\.findIndex\(card => card\.review_card_id === preferredCardId\)/);
    assert.match(loadCards, /if \(preferredIndex > 0\)[\s\S]*cards\.splice\(preferredIndex, 1\)[\s\S]*cards\.unshift\(preferredCard\)/);
});

test('back uses a local due-queue path first and otherwise delegates only the latest matching action to canonical undo', () => {
    const start = page.indexOf("goPreviousCard(source = 'sense_review_history')");
    const end = page.indexOf('goForwardCard()', start);
    assert.ok(start >= 0 && end > start);
    const section = page.slice(start, end);

    const localStart = section.indexOf('if (this.cards.some');
    const undoStart = section.indexOf('if (this.latestUndoableAction', localStart);
    assert.ok(localStart >= 0 && undoStart > localStart);
    const localBranch = section.slice(localStart, undoStart);
    assert.match(localBranch, /moveNavigationBack\(/);
    assert.match(localBranch, /this\.activateQueuedCard\(targetCardId\)/);
    assert.match(localBranch, /local_navigation:\s*true/);
    assert.doesNotMatch(localBranch, /requestUndo|reviewApi|axios|rateSenseCard|undoSenseReviewAction|ReviewLog|fsrs_/);

    const undoBranch = section.slice(undoStart);
    assert.match(undoBranch, /this\.latestUndoableAction\?\.review_card_id !== targetCardId/);
    assert.match(undoBranch, /this\.requestUndo\(this\.latestUndoableAction, source\)/);

    const requestUndoStart = page.indexOf('requestUndo(action, source)');
    const requestUndoEnd = page.indexOf('activateQueuedCard(cardId)', requestUndoStart);
    assert.ok(requestUndoStart >= 0 && requestUndoEnd > requestUndoStart);
    const requestUndo = page.slice(requestUndoStart, requestUndoEnd);
    assert.match(requestUndo, /this\.\$refs\.sessionActionsSurface\.requestUndo\(action, source\)/);

    const canonicalUndoStart = surface.indexOf('requestUndo(action, source)');
    const canonicalUndoEnd = surface.indexOf('ratingColor(rating)', canonicalUndoStart);
    assert.ok(canonicalUndoStart >= 0 && canonicalUndoEnd > canonicalUndoStart);
    const canonicalUndo = surface.slice(canonicalUndoStart, canonicalUndoEnd);
    assert.match(canonicalUndo, /reviewApi\.undoSenseReviewAction\(action\.review_log_id, payload\)/);
});

test('authoritative undo reconciliation also moves the page navigation pointer back', () => {
    const start = page.indexOf('onSessionActionUndone(data)');
    const end = page.indexOf('handleHotkey(event)', start);
    const section = page.slice(start, end);
    assert.match(section, /restoredCardId/);
    assert.match(section, /moveNavigationBack\(this\.navigationHistory, restoredCardId, currentCardId\)/);
    assert.match(section, /saveReviewNavigationHistory\(nextHistory\)/);
    assert.match(section, /this\.loadCards\(\)/);
});

test('forward only activates a target already loaded in the due queue and never enters rating or undo writes', () => {
    const availabilityStart = page.indexOf('forwardNavigationAvailable()');
    const availabilityEnd = page.indexOf('activeSessionActionCount()', availabilityStart);
    assert.ok(availabilityStart >= 0 && availabilityEnd > availabilityStart);
    const availability = page.slice(availabilityStart, availabilityEnd);
    assert.match(availability, /this\.cards\.some\(card => card\.review_card_id === this\.forwardNavigationTargetId\)/);

    const activateStart = page.indexOf('activateQueuedCard(cardId)');
    const activateEnd = page.indexOf("goPreviousCard(source = 'sense_review_history')", activateStart);
    assert.ok(activateStart >= 0 && activateEnd > activateStart);
    const activate = page.slice(activateStart, activateEnd);
    assert.match(activate, /this\.cards\.findIndex\(card => card\.review_card_id === cardId\)/);
    assert.match(activate, /this\.cards\.unshift\(card\)/);
    assert.doesNotMatch(activate, /reviewApi|axios|rateSenseCard|undoSenseReviewAction|requestUndo|ReviewLog|fsrs_/);

    const start = page.indexOf('goForwardCard()');
    const end = page.indexOf('onSessionActionStateChange', start);
    assert.ok(start >= 0 && end > start);
    const section = page.slice(start, end);
    assert.match(section, /targetCardId = this\.forwardNavigationTargetId/);
    assert.match(section, /this\.activateQueuedCard\(targetCardId\)/);
    assert.match(section, /moveNavigationForward\(/);
    assert.doesNotMatch(section, /axios|reviewApi|rateSenseCard|undoSenseReviewAction|requestUndo|ReviewLog|fsrs_/);
});

test('a successful rating records the rated card in navigation history and clears the old forward branch', () => {
    const rateStart = page.indexOf('rate(rating)');
    const rateEnd = page.indexOf('continueOverLimit()', rateStart);
    assert.ok(rateStart >= 0 && rateEnd > rateStart);
    const rateSection = page.slice(rateStart, rateEnd);
    const requestIndex = rateSection.indexOf('reviewApi.rateSenseCard');
    const recordIndex = rateSection.indexOf('recordRatedCard(');
    assert.ok(requestIndex >= 0 && recordIndex > requestIndex);
    assert.match(rateSection.slice(recordIndex), /cardSnapshot\.review_card_id/);

    const recordStart = history.indexOf('export function recordRatedCard');
    const recordEnd = history.indexOf('export function moveNavigationBack', recordStart);
    assert.ok(recordStart >= 0 && recordEnd > recordStart);
    const record = history.slice(recordStart, recordEnd);
    assert.match(record, /backCardIds:[\s\S]*currentId/);
    assert.match(record, /currentCardId:\s*null/);
    assert.match(record, /forwardCardIds:\s*\[\]/);
});

test('desktop shortcuts reuse the same page back and forward methods as the visible controls', () => {
    const start = page.indexOf('handleHotkey(event)');
    const end = page.indexOf('startEdit()', start);
    const section = page.slice(start, end);
    assert.match(section, /event\.shiftKey/);
    assert.match(section, /this\.goForwardCard\(\)/);
    assert.match(section, /this\.goPreviousCard\('sense_review_hotkey'\)/);
    assert.match(section, /event\.preventDefault\(\)/);
});

test('old previous-card information stays a secondary menu entry and reuses the existing backend undo source', () => {
    const toolbarStart = page.indexOf('<template #answer-toolbar>');
    const dialogStart = page.indexOf('<SenseReviewPreviousCardDialog', toolbarStart);
    assert.ok(toolbarStart >= 0 && dialogStart > toolbarStart);
    const secondaryUi = page.slice(toolbarStart, dialogStart);
    assert.match(secondaryUi, /上一张信息/);
    assert.match(secondaryUi, /previousCardDialog = true/);

    const dialogEnd = page.indexOf('/>', dialogStart);
    const dialog = page.slice(dialogStart, dialogEnd + 2);
    assert.match(dialog, /@undo="requestUndo\(\$event, 'sense_review_history'\)"/);
    assert.match(backendController, /'source' => \['required', 'in:sense_review_snackbar,sense_review_history,sense_review_hotkey'\]/);
    assert.doesNotMatch(page + backendController, /sense_review_previous_card/);
});

test('session action surface returns the authoritative undo result to the navigation caller', () => {
    assert.match(surface, /this\.\$emit\('undone', response\.data\);\s*return response\.data;/);
    assert.match(surface, /return null;/);
});

test('navigation helper persists only session identity and card ids, never learning facts', () => {
    assert.match(history, /sessionStorage/);
    const saveStart = history.indexOf('export function saveReviewNavigationHistory');
    const saveEnd = history.indexOf('export function recordRatedCard', saveStart);
    assert.ok(saveStart >= 0 && saveEnd > saveStart);
    const saveSection = history.slice(saveStart, saveEnd);
    assert.match(saveSection, /const normalized = saveShape\(history\)/);
    assert.match(saveSection, /storage\.setItem\(STORAGE_KEY, JSON\.stringify\(normalized\)\)/);

    const shapeStart = history.indexOf('function saveShape(history)');
    assert.ok(shapeStart >= 0);
    const shape = history.slice(shapeStart);
    const keys = [...shape.matchAll(/^\s*([A-Za-z][A-Za-z0-9]*):/gm)].map(match => match[1]);
    assert.deepEqual(keys, ['reviewSessionId', 'backCardIds', 'currentCardId', 'forwardCardIds']);
    assert.doesNotMatch(history, /localStorage\.(getItem|setItem|removeItem)|import\s+.*axios|axios\.|reviewApi\.|review_log_id|before_card_snapshot|after_card_snapshot|fsrs_[a-z]|reviewed_at|rating:/);
});
