import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const component = readFileSync(
    new URL('../../resources/js/components/Mobile/MobileSyncSimulator.vue', import.meta.url),
    'utf8',
);
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const webRoutes = readFileSync(new URL('../../routes/web.php', import.meta.url), 'utf8');

assert.match(component, /\/api\/v1\/mobile\/auth\/tokens/);
assert.match(component, /\/api\/v1\/mobile\/sync\/actions/);
assert.match(component, /\/api\/v1\/mobile\/review-packages\/short-term/);
assert.match(component, /Authorization: `Bearer \$\{this\.token\}`/);
assert.match(component, /beforeDestroy\(\)/);
assert.match(component, /this\.credentials\.password = ''/);
assert.match(component, /this\.token = ''/);
assert.doesNotMatch(component, /localStorage|sessionStorage|document\.cookie/);
assert.doesNotMatch(component, /\$store/);

for (const type of ['sense_review.rating', 'word_sense.update', 'word_sense.delete']) {
    assert.match(component, new RegExp(type.replace('.', '\\.')));
}
for (const state of ['queue-empty', 'simulator-error', 'partial-result', 'batch-status', 'load-review-package']) {
    assert.match(component, new RegExp(`data-testid="${state}"`));
}

assert.match(app, /path: '\/mobile-sync-simulator', component: MobileSyncSimulator/);
assert.match(webRoutes, /Route::get\('\/mobile-sync-simulator'.*HomeController::class, 'index'/);

console.log('Mobile sync simulator contract passed.');
