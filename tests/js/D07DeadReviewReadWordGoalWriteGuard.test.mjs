import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

const routesSource = readFileSync(new URL('../../routes/web.php', import.meta.url), 'utf8');
const controllerSource = readFileSync(
    new URL('../../app/Http/Controllers/ReviewController.php', import.meta.url),
    'utf8',
);
const chapterServiceSource = readFileSync(
    new URL('../../app/Services/ChapterService.php', import.meta.url),
    'utf8',
);
const goalServiceSource = readFileSync(
    new URL('../../app/Services/GoalService.php', import.meta.url),
    'utf8',
);
const requestUrl = new URL(
    '../../app/Http/Requests/Review/UpdateReviewGoalRequest.php',
    import.meta.url,
);

test('dead review read-word goal write stays removed while supported review and reading paths remain', () => {
    assert.doesNotMatch(routesSource, /Route::post\s*\(\s*['"]\/reviews\/update['"]/);
    assert.equal(controllerSource.includes('updateReadWordsGoal'), false);
    assert.equal(controllerSource.includes('UpdateReviewGoalRequest'), false);
    assert.equal(existsSync(requestUrl), false);
    assert.match(
        routesSource,
        /Route::post\s*\(\s*['"]\/reviews['"][\s\S]*?ReviewController::class\s*,\s*['"]getReviewItems['"]/,
    );
    assert.match(
        routesSource,
        /Route::post\s*\(\s*['"]\/reviews\/rate['"][\s\S]*?ReviewController::class\s*,\s*['"]rateReviewCard['"]/,
    );
    assert.match(
        routesSource,
        /Route::post\s*\(\s*['"]\/reviews\/senses\/\{reviewCardId\}\/rate['"][\s\S]*?SenseReviewController::class\s*,\s*['"]rate['"]/,
    );
    assert.match(controllerSource, /function\s+getReviewItems\s*\(/);
    assert.match(controllerSource, /function\s+rateReviewCard\s*\(/);
    assert.match(
        chapterServiceSource,
        /\$this->goalService->updateGoalAchievement\s*\(\s*\$userId\s*,\s*\$language\s*,\s*['"]read_words['"]\s*,\s*\$chapter->word_count\s*\)/,
    );
    assert.match(goalServiceSource, /['"]read_words['"]\s*=>\s*\[/);
});