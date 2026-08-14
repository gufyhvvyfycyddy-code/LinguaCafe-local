import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

const routesSource = readFileSync(new URL('../../routes/web.php', import.meta.url), 'utf8');
const controllerSource = readFileSync(
    new URL('../../app/Http/Controllers/VocabularyController.php', import.meta.url),
    'utf8',
);
const serviceSource = readFileSync(
    new URL('../../app/Services/VocabularyService.php', import.meta.url),
    'utf8',
);
const queryServiceSource = readFileSync(
    new URL('../../app/Services/VocabularyQueryService.php', import.meta.url),
    'utf8',
);
const reviewSource = readFileSync(
    new URL('../../resources/js/components/Review/Review.vue', import.meta.url),
    'utf8',
);
const textBlockGroupSource = readFileSync(
    new URL('../../resources/js/components/Text/TextBlockGroup.vue', import.meta.url),
    'utf8',
);
const requestUrl = new URL(
    '../../app/Http/Requests/Vocabulary/GetExampleSentenceRequest.php',
    import.meta.url,
);

test('legacy example-sentence GET chain stays retired while the POST writer remains', () => {
    assert.doesNotMatch(
        routesSource,
        /Route::get\s*\(\s*'\/vocabulary\/example-sentence\/\{targetType\}\/\{targetId\}'/,
    );
    assert.doesNotMatch(controllerSource, /function\s+getExampleSentence\s*\(/);
    assert.equal(controllerSource.includes('GetExampleSentenceRequest'), false);
    assert.equal(existsSync(requestUrl), false);
    assert.doesNotMatch(serviceSource, /function\s+getExampleSentence\s*\(/);
    assert.doesNotMatch(queryServiceSource, /function\s+getExampleSentence\s*\(/);

    assert.equal(reviewSource.includes('/vocabulary/example-sentence/'), false);

    assert.match(
        routesSource,
        /Route::post\s*\(\s*'\/vocabulary\/example-sentence\/create-or-update'.*'createOrUpdateExampleSentence'/,
    );
    assert.match(controllerSource, /function\s+createOrUpdateExampleSentence\s*\(/);
    assert.match(serviceSource, /function\s+createOrUpdateExampleSentence\s*\(/);
    assert.ok(
        textBlockGroupSource.includes("axios.post('/vocabulary/example-sentence/create-or-update'"),
    );
});
