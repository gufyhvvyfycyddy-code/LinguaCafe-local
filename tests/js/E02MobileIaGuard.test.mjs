import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = relative => readFileSync(new URL(`../../${relative}`, import.meta.url), 'utf8');
const ui = read('mobile/src/ui.ts');
const api = read('mobile/src/api.ts');
const routes = read('routes/api.php');
const controller = read('app/Http/Controllers/Mobile/MobileWordSenseController.php');

const navigation = ui.match(/<nav class="bottom-nav"[\s\S]*?<\/nav>/)?.[0] ?? '';
const vocabularyScreen = ui.match(/private async openVocabulary\(\)[\s\S]*?private async openSettings\(\)/)?.[0] ?? '';
assert.equal((navigation.match(/this\.navButton\(/g) ?? []).length, 4);
for (const item of [
  ["'library'", "'阅读'"],
  ["'review'", "'复习'"],
  ["'vocabulary'", "'生词'"],
  ["'settings'", "'我的'"],
]) {
  assert.match(navigation, new RegExp(`navButton\\(${item[0]}, ${item[1]}`));
}
assert.doesNotMatch(navigation, /'home'|'summary'|'文章'|'进度'|'设置'/);
assert.match(ui, /id="open-home" aria-label="首页"/);
assert.match(ui, /private screen: Screen = 'home'/);
assert.match(ui, /this\.api\.wordSenses\(\)/);
assert.doesNotMatch(vocabularyScreen, /ReviewCard|FSRS|legacy/i);

assert.match(routes, /Route::get\(\s*'\/word-senses'/);
assert.match(controller, /WordSenseLibraryQueryService/);
assert.match(controller, /'items' => \$page\['data'\]/);
assert.match(controller, /'read_only' => true/);
assert.doesNotMatch(api, /review-cards\/search.*wordSenses/s);

console.log('E-02 mobile IA guard passed.');
