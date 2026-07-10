// SenseReviewRatingPresentationGuard.test.mjs
//
// SenseReview-RatingPresentation-1000-2
//
// Node built-in assert tests for the SenseReview rating presentation
// contract. Guards the cross-cutting agreement between the frontend
// SenseReviewRatingPresentation.js and the backend
// app/Services/SenseReviewRatingContract.php.
//
// These tests guard:
//   1. SenseReviewRatingPresentation.js file exists.
//   2. The four rating values are again/hard/good/easy in stable order.
//   3. The four Chinese labels are 忘了/勉强记得/记得/很熟.
//   4. The four scores are 1/2/3/4.
//   5. The four hotkeys are 1/2/3/4.
//   6. hard label is exactly "勉强记得" (NOT "勉强").
//   7. hard score is still 2.
//   8. Backend RatingContract.php hard label is "勉强记得".
//   9. Backend RatingContract.php hard score is 2.
//  10. RatingControls.vue consumes RatingPresentation.
//  11. No user-visible孤立 "勉强" 文案 (isolated "勉强" without "记得").
//      Note: "勉强记得" contains "勉强", so we check for "勉强" NOT
//      immediately followed by "记得" in user-visible template text.
//  12. Catalog has exactly 4 reports in the right order.

import assert from 'node:assert/strict';
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const PRESENTATION_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewRatingPresentation.js');
const CATALOG_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewReportCatalog.js');
const CONTROLS_PATH = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses', 'SenseReviewRatingControls.vue');
const CONTRACT_PATH = join(__dirname, '..', '..', 'app', 'Services', 'SenseReviewRatingContract.php');
const SENSES_DIR = join(__dirname, '..', '..', 'resources', 'js', 'components', 'Senses');

let passed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (e) {
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
        process.exitCode = 1;
    }
}

console.log('SenseReviewRatingPresentation guard tests\n');

const presentationSrc = existsSync(PRESENTATION_PATH) ? readFileSync(PRESENTATION_PATH, 'utf-8') : '';
const catalogSrc = existsSync(CATALOG_PATH) ? readFileSync(CATALOG_PATH, 'utf-8') : '';
const controlsSrc = existsSync(CONTROLS_PATH) ? readFileSync(CONTROLS_PATH, 'utf-8') : '';
const contractSrc = existsSync(CONTRACT_PATH) ? readFileSync(CONTRACT_PATH, 'utf-8') : '';

// 1. Presentation file exists.
test('SenseReviewRatingPresentation.js file exists', () => {
    assert.ok(existsSync(PRESENTATION_PATH), 'SenseReviewRatingPresentation.js must exist');
});

// 2. Four rating values in stable order: again/hard/good/easy.
test('four rating values are again/hard/good/easy in order', () => {
    assert.ok(presentationSrc.includes("'again'"), 'presentation must have again');
    assert.ok(presentationSrc.includes("'hard'"), 'presentation must have hard');
    assert.ok(presentationSrc.includes("'good'"), 'presentation must have good');
    assert.ok(presentationSrc.includes("'easy'"), 'presentation must have easy');
    // Check order: again must appear before hard before good before easy.
    const againIdx = presentationSrc.indexOf("'again'");
    const hardIdx = presentationSrc.indexOf("'hard'");
    const goodIdx = presentationSrc.indexOf("'good'");
    const easyIdx = presentationSrc.indexOf("'easy'");
    assert.ok(againIdx < hardIdx, 'again must come before hard');
    assert.ok(hardIdx < goodIdx, 'hard must come before good');
    assert.ok(goodIdx < easyIdx, 'good must come before easy');
});

// 3. Four Chinese labels: 忘了/勉强记得/记得/很熟.
test('four Chinese labels are 忘了/勉强记得/记得/很熟', () => {
    assert.ok(presentationSrc.includes('忘了'), 'presentation must have 忘了');
    assert.ok(presentationSrc.includes('勉强记得'), 'presentation must have 勉强记得');
    assert.ok(presentationSrc.includes('记得'), 'presentation must have 记得');
    assert.ok(presentationSrc.includes('很熟'), 'presentation must have 很熟');
});

// 4. Four scores: 1/2/3/4.
test('four scores are 1/2/3/4', () => {
    assert.ok(presentationSrc.includes('score: 1'), 'again score must be 1');
    assert.ok(presentationSrc.includes('score: 2'), 'hard score must be 2');
    assert.ok(presentationSrc.includes('score: 3'), 'good score must be 3');
    assert.ok(presentationSrc.includes('score: 4'), 'easy score must be 4');
});

// 5. Four hotkeys: 1/2/3/4.
test('four hotkeys are 1/2/3/4', () => {
    assert.ok(presentationSrc.includes('hotkey: 1'), 'again hotkey must be 1');
    assert.ok(presentationSrc.includes('hotkey: 2'), 'hard hotkey must be 2');
    assert.ok(presentationSrc.includes('hotkey: 3'), 'good hotkey must be 3');
    assert.ok(presentationSrc.includes('hotkey: 4'), 'easy hotkey must be 4');
});

// 6. hard label is exactly "勉强记得" (NOT "勉强").
test('hard label is exactly 勉强记得', () => {
    // Find the hard entry's label in presentation source.
    const hardIdx = presentationSrc.indexOf("'hard'");
    assert.ok(hardIdx >= 0, 'must find hard entry');
    // Look at the chunk after 'hard' for the label.
    const chunk = presentationSrc.slice(hardIdx, hardIdx + 200);
    assert.ok(chunk.includes('勉强记得'), 'hard label must be 勉强记得');
});

// 7. hard score is still 2.
test('hard score is still 2', () => {
    const hardIdx = presentationSrc.indexOf("'hard'");
    const chunk = presentationSrc.slice(hardIdx, hardIdx + 200);
    assert.ok(chunk.includes('score: 2'), 'hard score must be 2');
});

// 8. Backend RatingContract.php hard label is "勉强记得".
test('backend RatingContract hard label is 勉强记得', () => {
    assert.ok(contractSrc.includes("'hard'  => '勉强记得'") || contractSrc.includes("'hard' => '勉强记得'"), 'backend hard label must be 勉强记得');
    assert.ok(!contractSrc.includes("'hard'  => '勉强'") && !contractSrc.includes("'hard' => '勉强',"), 'backend hard label must NOT be standalone 勉强');
});

// 9. Backend RatingContract.php hard score is 2.
test('backend RatingContract hard score is 2', () => {
    assert.ok(contractSrc.includes("'hard'  => 2") || contractSrc.includes("'hard' => 2"), 'backend hard score must be 2');
});

// 10. RatingControls.vue consumes RatingPresentation.
test('RatingControls.vue consumes SenseReviewRatingPresentation', () => {
    assert.ok(controlsSrc.includes('SenseReviewRatingPresentation'), 'RatingControls must import RatingPresentation');
    assert.ok(controlsSrc.includes('RATING_PRESENTATION'), 'RatingControls must use RATING_PRESENTATION');
});

// 11. No user-visible isolated "勉强" 文案 in SenseReview .vue templates.
// "勉强记得" is fine; "勉强" alone (not followed by "记得") is a bug.
test('no isolated 勉强 (without 记得) in SenseReview vue templates', () => {
    const vueFiles = readdirSync(SENSES_DIR)
        .filter((f) => f.startsWith('SenseReview') && f.endsWith('.vue'));
    for (const file of vueFiles) {
        const filePath = join(SENSES_DIR, file);
        const src = readFileSync(filePath, 'utf-8');
        // Strip JS/CSS comments to avoid false positives from历史注释.
        const stripped = src
            .replace(/\/\/[^\n]*/g, '')
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/<!--[\s\S]*?-->/g, '');
        // Find all occurrences of "勉强" and check the following chars.
        let idx = 0;
        while (true) {
            idx = stripped.indexOf('勉强', idx);
            if (idx === -1) break;
            const following = stripped.slice(idx + 2, idx + 4);
            assert.ok(following.startsWith('记得'), `${file}: isolated "勉强" found without "记得" (context: "${stripped.slice(idx, idx + 10)}")`);
            idx += 4;
        }
    }
});

// 12. Catalog has exactly 4 reports in the right order.
test('Catalog has exactly 4 reports in order today-summary/daily-report/seven-day-trend/thirty-day-calendar', () => {
    const keys = ['today-summary', 'daily-report', 'seven-day-trend', 'thirty-day-calendar'];
    let prevIdx = -1;
    for (const key of keys) {
        const idx = catalogSrc.indexOf(key);
        assert.ok(idx >= 0, `Catalog must contain ${key}`);
        assert.ok(idx > prevIdx, `Catalog order: ${key} must come after previous`);
        prevIdx = idx;
    }
});

console.log(`\n${passed} passed`);
