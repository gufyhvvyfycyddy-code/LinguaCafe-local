import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const review = fs.readFileSync(path.join(root, 'resources/js/components/Senses/SenseReview.vue'), 'utf8');
const library = fs.readFileSync(path.join(root, 'resources/js/components/Senses/WordSenseLibrary.vue'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'resources/js/components/Layout.vue'), 'utf8');

test('mobile review summary actions keep a 44px touch target', () => {
    assert.match(
        review,
        /@media\s*\(max-width:\s*600px\)[\s\S]*?\.sense-review-summary\s+\.v-btn\s*\{[\s\S]*?min-height:\s*44px;[\s\S]*?min-width:\s*44px;/,
    );
});

test('mobile WordSense search controls keep a 44px touch target', () => {
    assert.doesNotMatch(library, /<v-text-field[\s\S]*?\bdense\b[\s\S]*?>/);
    assert.match(
        library,
        /@media\s*\(max-width:\s*430px\)[\s\S]*?\.word-sense-search\s+\.v-btn\s*\{[\s\S]*?min-height:\s*44px;/,
    );
});

test('mobile More navigation keeps a 44px touch target', () => {
    assert.match(
        layout,
        /id="mobile-more-trigger"[\s\S]*?height="44"/,
    );
});
