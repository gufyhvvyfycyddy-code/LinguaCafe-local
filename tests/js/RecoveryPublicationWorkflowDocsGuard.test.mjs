import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');
const readJson = (...parts) => JSON.parse(read(...parts));

const masterPlan = read('docs', 'plans', 'linguacafe-recovery-publication-master-plan-2026-08.md');
const publicationPlan = read('docs', 'plans', 'cfh-02-m6-publication-plan.md');
const implementationPlan = read('docs', 'plans', 'm6-resilience-health-isolation-implementation-plan.md');
const adr0055 = read('docs', 'adr', 'ADR-0055-single-owner-restore-without-user-visible-preview.md');
const milestone = readJson('docs', 'execution', 'CURRENT_MILESTONE.json');
const ownership = readJson('docs', 'audits', 'cfh-01-worktree-ownership-map-2026-08-04.json');

assert.equal(milestone.schema_version, 2);
assert.equal(milestone.program_id, 'linguacafe-recovery-publication-2026-08');
assert.equal(milestone.active_task, 'NONE');
assert.equal(milestone.status, 'accepted');
assert.deepEqual(milestone.allowed_work, []);
assert.equal(milestone.product_code_authorized, false);
assert.equal(milestone.commit_product_code_allowed, false);
assert.equal(milestone.migration_execution_allowed, false);
assert.equal(milestone.database_write_allowed, false);
assert.equal(milestone.database_write_scope, 'none');
assert.equal(milestone.browser_required, false);
assert.equal(milestone.device_required, false);
assert.equal(milestone.auto_advance, false);
assert.equal(milestone.supervisor_unlock_required, false);

assert.match(masterPlan, /document_status: completed/);
assert.match(masterPlan, /active_task: NONE/);
assert.match(masterPlan, /M6A–M6D are therefore not candidate tasks/);
assert.match(masterPlan, /ADR-0055 is the current public restore authority/);
assert.match(masterPlan, /The recovery\/publication program itself has no active task/);
assert.match(masterPlan, /auto_advance: false/);

assert.match(publicationPlan, /Status: `ACCEPTED \/ PUBLISHED`/);
assert.match(publicationPlan, /M6A–M6D are `Accepted \/ Closed`/);
assert.match(publicationPlan, /CFH-02_M6_PUBLICATION_ACCEPTED/);
assert.match(publicationPlan, /Active task: none\. Auto-advance: false/);

assert.match(implementationPlan, /M6A–M6D Accepted \/ Closed; M6 Closed/);
assert.match(implementationPlan, /server-preflighted confirmation-gated restore without a user-visible/);
assert.match(implementationPlan, /server-side archive validation with no client preview token/);
assert.match(implementationPlan, /equal access for authenticated\s+users without an admin-role check/);
for (const stale of [
    'archive validation and preview token',
    'admin preview/confirm UI',
    'Tokens prove expiry, single use, admin binding',
    'Feature tests prove administrator-only access',
]) {
    assert.ok(!implementationPlan.includes(stale), `implementation plan 不得保留当前冲突契约: ${stale}`);
}

assert.match(adr0055, /Status: Accepted \/ Implemented \/ Production Closed/);
assert.match(adr0055, /Supersedes in part: ADR-0036/);
assert.match(adr0055, /Every authenticated user has the same/);
assert.match(adr0055, /there is no user-visible restore preview/i);
assert.match(adr0055, /current authority for the public restore contract/);

assert.ok(existsSync(join(root, 'docs', 'plans', 'codex-final-handoff-2026-08-04.md')));
assert.equal(ownership.schema_version, 2);
assert.ok(Array.isArray(ownership.entries) && ownership.entries.length > 0);
assert.equal(new Set(ownership.entries.map((entry) => entry.path)).size, ownership.entries.length);
for (const entry of ownership.entries) {
    assert.ok(!entry.path.startsWith('/'), `ownership path 必须相对: ${entry.path}`);
    assert.ok(!/^[A-Za-z]:[\\/]/.test(entry.path), `ownership path 不得为盘符绝对路径: ${entry.path}`);
    assert.ok(!entry.path.split(/[\\/]/).includes('..'), `ownership path 不得逃逸: ${entry.path}`);
    assert.ok(!entry.path.includes('.env'), `ownership path 不得包含 .env: ${entry.path}`);
}

const currentText = [masterPlan, publicationPlan, implementationPlan, adr0055, JSON.stringify(milestone)].join('\n');
assert.ok(!/[A-Za-z]:\\(?:Users|Document|Temp)\\/i.test(currentText), '当前治理文档不得包含本地绝对路径');
assert.ok(!/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/.test(currentText), '当前治理文档不得包含账号邮箱');

console.log('Recovery publication workflow closure guard passed.');
