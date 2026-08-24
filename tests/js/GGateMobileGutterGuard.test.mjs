import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const customStudy = fs.readFileSync(
    path.join(root, 'resources/js/components/CustomStudy/CustomStudy.vue'),
    'utf8'
);
const articleHealth = fs.readFileSync(
    path.join(root, 'resources/js/components/Health/ArticleHealth.vue'),
    'utf8'
);

test('430px content owners disable the mismatched top-level row gutter', () => {
    assert.match(customStudy, /<v-row\s+no-gutters>/);
    assert.match(articleHealth, /<v-row\s+no-gutters\s+justify="center">/);
});
