import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');

// ---- 1. 恢复与发布总计划必须存在且处于 current 状态 ----
const masterPlan = read('docs', 'plans', 'linguacafe-recovery-publication-master-plan-2026-08.md');
assert.match(masterPlan, /^---$/m);
assert.match(masterPlan, /document_status: current/);
assert.match(masterPlan, /program_id: linguacafe-recovery-publication-2026-08/);
assert.match(masterPlan, /authoritative_handoff: docs\/plans\/codex-final-handoff-2026-08-04\.md/);
assert.match(masterPlan, /active_task: CFH-01/);
assert.match(masterPlan, /auto_advance: false/);
assert.match(masterPlan, /product_code_authorized: false/);
assert.match(masterPlan, /supervisor_unlock_required: true/);

// ---- 2. 权威 handoff 必须存在（总计划声明的唯一恢复入口） ----
const handoff = read('docs', 'plans', 'codex-final-handoff-2026-08-04.md');
assert.match(handoff, /task_id: `CFH-01`/);
assert.match(handoff, /Freeze an exact commit map/);
assert.ok(handoff.includes('docs/DOCUMENTATION_INDEX.md'));
assert.ok(handoff.includes('scripts/workspace-inventory.mjs'));

// ---- 3. 归属图必须存在、可解析且覆盖全部 462 条基线路径 ----
const ownershipRaw = read('docs', 'audits', 'cfh-01-worktree-ownership-map-2026-08-04.json');
const ownership = JSON.parse(ownershipRaw);
assert.equal(ownership.schema_version, 1);
assert.equal(ownership.baseline.counts.tracked_modified, 110);
assert.equal(ownership.baseline.counts.tracked_deleted, 2);
assert.equal(ownership.baseline.counts.untracked, 350);
assert.equal(ownership.baseline.counts.total, 462);
assert.deepEqual(ownership.baseline.conflicts, []);
assert.match(ownership.baseline.head, /^[0-9a-f]{40}$/);
assert.match(ownership.baseline.origin_master, /^[0-9a-f]{40}$/);
assert.equal(ownership.entries.length, 462);
assert.equal(Object.values(ownership.summary).reduce((a, b) => a + b, 0), 462);
const recomputedSummary = ownership.entries.reduce((acc, e) => {
    acc[e.primary_slice] = (acc[e.primary_slice] || 0) + 1;
    return acc;
}, {});
assert.deepEqual(recomputedSummary, ownership.summary, 'summary 必须与 entries 逐 slice 一致');

const allowedStatuses = new Set(['modified', 'deleted', 'untracked']);
const seenPaths = new Set();
const allowedSlices = new Set([
    'GOVERNANCE', 'CFH-02', 'CFH-03-M1', 'CFH-03-M2', 'CFH-03-M3', 'CFH-03-M4', 'CFH-03-M5',
    'CFH-04-M7', 'CFH-04-M8', 'CFH-05-M9', 'M10', 'M11', 'M12', 'M13', 'M14', 'M15', 'M16',
    'M17-WEB-ANDROID', 'M18-WEB-ANDROID', 'SHARED_UNRESOLVED', 'DO_NOT_COMMIT', 'local_agent_metadata',
]);
for (const entry of ownership.entries) {
    assert.ok(entry.path && entry.path.length > 0, 'entry 必须有 path');
    assert.ok(allowedStatuses.has(entry.status), `非法 status: ${entry.path} -> ${entry.status}`);
    assert.ok(allowedSlices.has(entry.primary_slice), `非法 primary_slice: ${entry.path} -> ${entry.primary_slice}`);
    assert.ok(entry.evidence && entry.evidence.length > 0, 'entry 必须有 evidence');
    assert.ok(!seenPaths.has(entry.path), `重复路径: ${entry.path}`);
    seenPaths.add(entry.path);
}
assert.ok(
    ownership.entries.some((e) => e.primary_slice === 'SHARED_UNRESOLVED'),
    '归属图必须显式标记无法唯一归属的共享路径',
);
assert.ok(
    ownership.entries.some((e) => e.primary_slice === 'local_agent_metadata'),
    '归属图必须登记本地 Agent 元数据（不提交）',
);
assert.ok(
    ownership.entries.some((e) => e.primary_slice === 'DO_NOT_COMMIT'),
    '归属图必须登记生成物（不提交）',
);

// ---- 4. 里程碑锁必须存在且指向当前任务 ----
const milestone = JSON.parse(read('docs', 'execution', 'CURRENT_MILESTONE.json'));
assert.equal(milestone.active_task, 'CFH-01');

// ---- 5. 总计划停止规则必须保留（归属图不可用于自动切分） ----
assert.ok(masterPlan.includes('文件具有多个无法分离的所有者'));
assert.ok(masterPlan.includes('唯一机器可读依据'));
assert.ok(masterPlan.includes('LOCAL_UNATTRIBUTED'));
assert.ok(masterPlan.includes('PUSHED_AWAITING_ACCEPTANCE'));
assert.ok(masterPlan.includes('BLOCKED_EXTERNAL'));
assert.ok(masterPlan.includes('DO_NOT_COMMIT'));
assert.ok(masterPlan.includes('status: candidate_not_authorized'));
assert.ok(!masterPlan.includes('status: authorized'), 'CFH-01 阶段候选队列不得被授权');

console.log('Recovery publication workflow docs guard passed.');
