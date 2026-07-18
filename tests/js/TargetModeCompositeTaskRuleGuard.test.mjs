import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const rules = fs.readFileSync(path.join(root, 'docs/plans/vibe-coding-collaboration-rules.md'), 'utf8');

assert.match(rules, /任务规模由风险和可验证 seam 决定/);
assert.match(rules, /一个任务只交付一个可陈述结果/);
assert.match(rules, /小修不与无关架构工作捆绑/);
assert.match(rules, /禁止为了流程形式制造抽象、拆分或文档/);
assert.match(rules, /文件数或 seam 明显增长时拆分/);
assert.match(rules, /完成当前任务后停止/);
assert.doesNotMatch(rules, /最低复杂度为 100/);
assert.doesNotMatch(rules, /每个下一步提示词都是复合型任务/);

console.log('Risk-sized task rule contract passed.');
