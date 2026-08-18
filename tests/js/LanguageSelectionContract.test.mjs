import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const layout = read('resources/js/components/Layout.vue');
const account = read('resources/js/components/UserSettings/UserSettingsAccount.vue');
const installDialog = read('resources/js/components/Admin/AdminInstallLanguageDialog.vue');
const app = read('resources/js/app.js');
const webRoutes = read('routes/web.php');
const mobileUi = read('mobile/src/ui.ts');
const offlineRepository = read('mobile/src/offlineRepository.ts');

test('ordinary web layout has no language switcher or Japanese Kanji navigation', () => {
    assert.equal(layout.includes('<language-selection-dialog'), false);
    assert.equal(layout.includes('languageSelectionDialog'), false);
    assert.equal(layout.includes('id="language"'), false);
    assert.equal(layout.includes('学习语言：'), false);
    assert.equal(layout.includes("this.$props._selectedLanguage == 'japanese'"), false);
    assert.equal(layout.includes("url: '/kanji/search'"), false);
    assert.equal(layout.includes(':language="selectedLanguage"'), true);
});

test('ordinary account deletion surface is fixed to English', () => {
    assert.equal(account.includes('删除英语学习数据'), true);
    assert.equal(account.includes('delete all my english data'), true);
    assert.equal(account.includes("axios.delete('/users/delete-language-data/english')"), true);
    assert.equal(account.includes('/images/flags/'), false);
    assert.equal(account.includes('其他学习语言'), false);
});

test('admin language package install no longer auto-selects the installed language', () => {
    assert.equal(installDialog.includes("axios.post('/languages/install'"), true);
    assert.equal(installDialog.includes('/languages/select/'), false);
    assert.equal(installDialog.includes('selectNewLanguage'), false);
    assert.equal(installDialog.includes('切换到'), false);
});

test('ordinary Kanji SPA routes retire while lower Kanji and JMDict owners remain', () => {
    assert.equal(app.includes("./components/Kanji/KanjiList.vue"), false);
    assert.equal(app.includes("./components/Kanji/KanjiDetails.vue"), false);
    assert.equal(app.includes("path: '/kanji/search'"), false);
    assert.equal(app.includes("path: '/kanji/:character'"), false);
    assert.equal(webRoutes.includes("Route::get('/kanji/search'"), false);
    assert.equal(webRoutes.includes("Route::get('/kanji/{character}'"), false);
    assert.equal(webRoutes.includes("Route::post('/kanji/search'"), true);
    assert.equal(webRoutes.includes("Route::post('/kanji/details'"), true);
    assert.equal(webRoutes.includes("Route::get('/images/kanji/{fileName}'"), true);
    assert.equal(webRoutes.includes("Route::get('/jmdict/xml-to-text'"), true);
    assert.equal(fs.existsSync(path.join(root, 'app/Models/Kanji.php')), true);
    assert.equal(fs.existsSync(path.join(root, 'app/Models/VocabularyJmdict.php')), true);
});

test('mobile hides visible language identity but keeps current-language offline scope', () => {
    assert.equal(mobileUi.includes('class="language-pill"'), false);
    assert.equal(mobileUi.includes('${escapeHtml(this.bootstrap?.current_language)} · 已连接'), false);
    assert.equal(mobileUi.includes('this.bootstrap.current_language'), true);
    assert.equal(offlineRepository.includes('this.scope = `user:${userId}:language:${language}`'), true);
});
