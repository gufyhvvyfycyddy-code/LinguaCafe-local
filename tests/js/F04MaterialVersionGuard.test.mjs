import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const dialog = read('resources/js/components/Library/EditBookChapterDialog.vue');
const request = read('app/Http/Requests/Chapters/UpdateChapterRequest.php');
const chapterService = read('app/Services/ChapterService.php');
const packageService = read('app/Services/MobileArticlePackageService.php');

test('chapter editor sends the server-issued source revision', () => {
    assert.match(dialog, /this\.sourceRevision = response\.data\.source_revision/);
    assert.match(dialog, /data\.sourceRevision = this\.sourceRevision/);
    assert.match(request, /'sourceRevision' => \['required', 'string', 'regex:/);
});

test('chapter updates compare the revision under the existing row lock', () => {
    assert.match(chapterService, /::lockForUpdate\(\)/);
    assert.match(chapterService, /hash_equals\(\$this->readingChapterTextService->sourceRevision\(\$chapter\), \$sourceRevision\)/);
    assert.match(chapterService, /ERROR_SOURCE_REVISION_CONFLICT/);
});

test('material metadata participates in the existing mobile package checksum', () => {
    for (const field of ['material_type', 'exam_year', 'exam_set']) {
        assert.equal((packageService.match(new RegExp(`'${field}' => \\$book->${field}`, 'g')) || []).length, 2);
    }
});
