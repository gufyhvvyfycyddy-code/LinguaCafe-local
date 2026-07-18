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

test('Browser table exposes row, bulk, and marked-study Marker controls', () => {
    const source = fs.readFileSync(path.join(reviewCardDir, 'ReviewCardTableSurface.vue'), 'utf8');

    assert.match(source, /import ReviewCardMarkerPicker/);
    assert.match(source, /<review-card-marker-picker[\s\S]+:card-id="item\.review_card_id"/);
    assert.match(source, /<review-card-marker-picker[\s\S]+:ids="selectedIds"/);
    assert.match(source, /marker-updated/);
    assert.match(source, /bulk-marker-updated/);
    assert.match(source, /学习已标记卡片/);
    assert.match(source, /study-marked/);
});

test('Card Info reuses the picker and emits canonical Marker updates', () => {
    const source = fs.readFileSync(path.join(reviewCardDir, 'ReviewCardInfoDrawer.vue'), 'utf8');

    assert.match(source, /import ReviewCardMarkerPicker/);
    assert.match(source, /<review-card-marker-picker/);
    assert.match(source, /detailTarget\.marker/);
    assert.match(source, /marker-updated/);
    assert.match(source, /学习已标记卡片/);
    assert.match(source, /study-marked/);
});

test('management container reconciles Marker events and passes no card IDs to Custom Study', () => {
    const source = fs.readFileSync(path.join(reviewCardDir, 'ReviewCardManage.vue'), 'utf8');

    assert.match(source, /@marker-updated="onMarkerUpdated"/);
    assert.match(source, /@bulk-marker-updated="onBulkMarkerUpdated"/);
    assert.match(source, /@study-marked="openMarkedStudy"/);
    assert.match(source, /path:\s*['"]\/custom-study['"]/);
    assert.match(source, /query:\s*\{\s*mode:\s*['"]marked['"]\s*\}/);
    assert.doesNotMatch(source, /query:\s*\{[^}]*ids/);
});
