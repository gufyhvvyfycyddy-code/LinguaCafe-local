import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const api = read('mobile/src/api.ts');
const repository = read('mobile/src/offlineRepository.ts');
const ui = read('mobile/src/ui.ts');

test('article catalog keeps the existing server manifest version', () => {
    assert.match(api, /content_version: item\.content_version/);
});

test('offline status derives from the existing chapter package cache', () => {
    assert.match(repository, /bookDownloadState\(bookId: number\)/);
    assert.match(repository, /Object\.keys\(state\.chapter_packages \?\? \{\}\)/);
    assert.match(repository, /state\.downloaded_books\[String\(bookId\)\] = contentVersion/);
});

test('explicit set download caches packages without creating reading sessions', () => {
    const downloadMethod = ui.slice(
        ui.indexOf('private async downloadBook'),
        ui.indexOf('private async openChapter'),
    );
    assert.match(downloadMethod, /this\.api\.chapterPackage\(book\.book_id, chapter\.chapter_id\)/);
    assert.match(downloadMethod, /this\.offlineRepository\.saveChapterPackage/);
    assert.match(downloadMethod, /this\.offlineRepository\.markBookDownloaded/);
    assert.doesNotMatch(downloadMethod, /startReadingSession/);
});

test('material and chapter screens expose offline and update status', () => {
    for (const copy of ['未下载', '已下载整套 · 可离线打开', '本机整套已有新版本可更新', '下载整套']) {
        assert.match(ui, new RegExp(copy));
    }
});
