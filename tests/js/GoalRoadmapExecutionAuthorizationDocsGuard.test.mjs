import assert from 'node:assert/strict';
import fs from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const agents = fs.readFileSync(join(root, 'AGENTS.md'), 'utf8');
const adr = fs.readFileSync(
  join(root, 'docs/adr/ADR-0031-goal-mode-roadmap-execution-authorization.md'),
  'utf8',
);
const roadmap = fs.readFileSync(
  join(root, 'docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md'),
  'utf8',
);

assert.match(agents, /目标模式 roadmap 执行授权/);
assert.match(agents, /testing 专用数据库/);
assert.match(agents, /开发\/预发布\/生产或真实用户数据上的 migration 执行/);
assert.match(agents, /部署、签名、商店提交/);
assert.match(agents, /依据见 ADR-0031/);

assert.match(adr, /supersedes ADR-0028/);
assert.match(adr, /dedicated testing database/);
assert.match(adr, /does not authorize `php artisan migrate`/);
assert.match(adr, /real AI providers/);
assert.match(adr, /automatic entry into the next named milestone/);

assert.match(roadmap, /满足 ADR-0034 的 deferred-acceptance 依赖证明/);
assert.match(roadmap, /明确持续目标按 ADR-0031 完成逐项审计后自动进入下一个满足依赖的命名里程碑/);
assert.doesNotMatch(roadmap, /完成后不得自动进入下一里程碑/);

console.log('Goal roadmap execution authorization docs guard passed.');
