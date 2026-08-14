import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const routesSource = readFileSync(new URL('../../routes/web.php', import.meta.url), 'utf8');
const controllerSource = readFileSync(
    new URL('../../app/Http/Controllers/VocabularyController.php', import.meta.url),
    'utf8',
);
const serviceSource = readFileSync(
    new URL('../../app/Services/VocabularyService.php', import.meta.url),
    'utf8',
);

test('dead vocabulary batch-delete entry stays removed while supported vocabulary paths remain', () => {
    assert.equal(routesSource.includes("'/vocabulary/words/batch-delete'"), false);
    assert.equal(controllerSource.includes('batchDeleteWords'), false);

    assert.ok(routesSource.includes("Route::post('/vocabulary/words/batch-ignore'"));
    assert.ok(routesSource.includes("Route::post('/vocabulary/words/batch-hard-delete'"));
    assert.ok(routesSource.includes("Route::post('/vocabulary/words/bulk-hard-delete-count'"));
    assert.ok(routesSource.includes("Route::post('/vocabulary/words/bulk-hard-delete'"));
    assert.ok(routesSource.includes("Route::post('/vocabulary/word/delete'"));
    assert.ok(routesSource.includes("Route::post('/vocabulary/search'"));
    assert.ok(routesSource.includes("Route::post('/vocabulary/example-sentence/create-or-update'"));
    assert.ok(serviceSource.includes('function softDeleteWord('));
});
