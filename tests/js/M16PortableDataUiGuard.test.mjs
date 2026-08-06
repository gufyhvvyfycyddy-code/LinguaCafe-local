import assert from 'node:assert/strict';
import fs from 'node:fs';

const panel = fs.readFileSync(
    new URL('../../resources/js/components/ReviewCards/PortableDataPanel.vue', import.meta.url),
    'utf8',
);
const manage = fs.readFileSync(
    new URL('../../resources/js/components/ReviewCards/ReviewCardManage.vue', import.meta.url),
    'utf8',
);

for (const text of [
    '.apkg / JSON / CSV 默认不携带学习历史',
    '显式为 .apkg / JSON / CSV 包含可映射的调度状态',
    '健康检查并预览',
    '创建恢复点并应用',
    '.apkg / .json / .csv / .lcpkg',
]) {
    assert.ok(panel.includes(text), `missing portable-data UX contract: ${text}`);
}
assert.ok(panel.includes(':disabled="!confirmed"'));
assert.ok(panel.includes('preview.can_apply'));
assert.ok(panel.includes("kind !== 'full' && this.includeScheduling"));
assert.ok(manage.includes('<portable-data-panel'));

console.log('M16 portable data UI guard passed.');
