import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const ui = readFileSync(new URL('../../mobile/src/ui.ts', import.meta.url), 'utf8');

assert.match(ui, /function syncIssueCopy\(issue: OfflineSyncIssue\)/);
assert.match(ui, /待同步操作仍保留在本机，请稍后重试/);
assert.match(ui, /this\.usingOfflineSnapshot \|\| !navigator\.onLine/);
assert.match(ui, /因此没有覆盖新结果/);
assert.match(ui, /服务器现有记录保持不变/);
assert.doesNotMatch(ui, /escapeHtml\(issue\.(?:code|message)\)/);
assert.doesNotMatch(ui, /watchdog|setInterval\(|BackgroundTask|background worker/i);

console.log('E-05 mobile sync recovery guard passed.');
