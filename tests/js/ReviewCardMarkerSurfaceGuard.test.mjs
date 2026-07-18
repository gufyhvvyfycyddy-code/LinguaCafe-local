import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const reviewCardDir = path.resolve('resources/js/components/ReviewCards');
const pickerPath = path.join(reviewCardDir, 'ReviewCardMarkerPicker.vue');

test('one marker picker owns both write endpoints', () => {
    assert.equal(fs.existsSync(pickerPath), true, 'ReviewCardMarkerPicker.vue must exist');
    const source = fs.readFileSync(pickerPath, 'utf8');

    assert.match(source, /axios\.patch\([^\n]+\/marker/);
    assert.match(source, /axios\.post\(['"]\/review-cards\/manage\/bulk-marker/);
    assert.match(source, /\$emit\(['"]updated['"]/);
    assert.match(source, /:loading=["']saving["']/);
    assert.match(source, /aria-label/);
});

test('no other ReviewCard Vue component owns marker HTTP writes', () => {
    const offenders = fs.readdirSync(reviewCardDir)
        .filter(name => name.endsWith('.vue') && name !== 'ReviewCardMarkerPicker.vue')
        .filter(name => {
            const source = fs.readFileSync(path.join(reviewCardDir, name), 'utf8');
            return /review-cards\/manage\/(?:bulk-marker|[^'"`\s]+\/marker)/.test(source);
        });

    assert.deepEqual(offenders, []);
});
