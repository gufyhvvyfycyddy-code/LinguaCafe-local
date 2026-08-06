import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = relative => readFileSync(new URL(`../../${relative}`, import.meta.url), 'utf8');

const textBlock = read('resources/js/components/Text/TextBlockGroup.vue');
const bottomSheet = read('resources/js/components/Text/VocabularyBottomSheet.vue');
const bottomSheetStyle = read('resources/sass/Text/VocabularyBottomSheet.scss');
const studyCard = read('resources/js/components/Senses/SenseStudyCard.vue');
const ratingControls = read('resources/js/components/Senses/SenseReviewRatingControls.vue');
const review = read('resources/js/components/Senses/SenseReview.vue');
const adr = read('docs/adr/ADR-0043-m5-mobile-reader-reviewer-touch-adaptation.md');
const plan = read('docs/plans/m5-mobile-reader-reviewer-touch-adaptation-plan.md');
const acceptance = read('docs/testing/m5-mobile-reader-reviewer-touch-acceptance-2026-07-29.md');

assert.match(textBlock, /@touchcancel="cancelSelectionTouchEvent"/);
assert.match(textBlock, /@touchend="finishSelectionTouchEvent"/);
assert.match(textBlock, /window\.matchMedia\('\(hover: hover\) and \(pointer: fine\)'\)/);
assert.match(textBlock, /hoverInteractionAvailable && \$store\.state\.hoverVocabularyBox\.active/);
assert.match(textBlock, /shouldUseVocabularyBottomSheet/);
assert.match(
    textBlock,
    /if \(\(action === 'tap' \|\| action === 'finish'\) && event\.cancelable\) \{\s*event\.preventDefault\(\);/s,
);
assert.match(textBlock, /__linguacafeMobileVocabularySheet/);
assert.match(textBlock, /window\.addEventListener\('popstate', this\.handleMobileSheetPopState\)/);
assert.match(textBlock, /window\.removeEventListener\('popstate', this\.handleMobileSheetPopState\)/);
assert.match(textBlock, /window\.history\.replaceState/);

assert.match(bottomSheet, /data-testid="vocabulary-bottom-sheet-card"/);
assert.match(bottomSheet, /data-testid="close-mobile-vocabulary-sheet"/);
assert.match(bottomSheet, /轻点查词；长按后拖动可选择短语/);
assert.match(bottomSheet, /aria-label="关闭点词面板"/);
assert.match(bottomSheetStyle, /max-height: 86dvh/);
assert.match(bottomSheetStyle, /env\(safe-area-inset-bottom/);
assert.match(bottomSheetStyle, /min-height: 48px/);
assert.match(bottomSheetStyle, /min-width: 44px/);
assert.match(bottomSheetStyle, /overscroll-behavior/);

assert.match(studyCard, /data-testid="view-sense-source"/);
assert.match(studyCard, /data-testid="show-sense-answer"/);
assert.match(studyCard, /@media \(max-width: 600px\)/);
assert.match(studyCard, /min-height: 52px/);
assert.match(ratingControls, /grid-template-columns: repeat\(2/);
assert.match(ratingControls, /env\(safe-area-inset-bottom/);
assert.match(ratingControls, /data-testid="sense-rating-controls"/);
assert.match(ratingControls, /data-testid="`sense-rating-\$\{rating\.value\}`"/);
assert.doesNotMatch(ratingControls, /axios|fetch\(/);
assert.match(review, /class="mobile-reveal-button"/);
assert.match(review, /data-testid="sense-review-page"/);

for (const width of ['360', '390', '430']) {
    assert.match(adr, new RegExp(width));
}
assert.match(adr, /tablet/i);
assert.match(plan, /backend controllers\/services\/routes\/payloads/);
assert.match(plan, /ReviewLog\/FSRS/);
assert.match(plan, /Accepted \/ Closed/);
assert.match(acceptance, /Accepted \/ Closed/);
assert.match(acceptance, /M1 deferred Web rating seam/i);
assert.match(acceptance, /database_is_testing/);

console.log('M5 mobile touch adaptation guard passed.');
