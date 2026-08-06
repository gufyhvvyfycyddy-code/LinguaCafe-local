import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');
const readJson = (...parts) => JSON.parse(read(...parts));

const milestone = readJson('docs', 'execution', 'CURRENT_MILESTONE.json');
const manifest = readJson('docs', 'audits', 'cfh-02-m6-exact-slice-manifest-2026-08-05.json');
const m6aEvidence = readJson('docs', 'testing', 'cfh-02b-m6a-mcp-chrome-evidence-2026-08-05.json');
const m6bEvidence = readJson('docs', 'testing', 'cfh-02b-m6b-mcp-chrome-evidence-2026-08-05.json');
const m6aReport = read('docs', 'testing', 'cfh-02b-m6a-publication-acceptance-2026-08-05.md');
const m6bReport = read('docs', 'testing', 'cfh-02b-m6b-responsive-restore-acceptance-2026-08-05.md');
const implementationPlan = read('docs', 'plans', 'm6-resilience-health-isolation-implementation-plan.md');
const publicationPlan = read('docs', 'plans', 'cfh-02-m6-publication-plan.md');
const adr0055 = read('docs', 'adr', 'ADR-0055-single-owner-restore-without-user-visible-preview.md');

assert.equal(milestone.active_task, 'NONE');
assert.equal(milestone.status, 'accepted');
assert.equal(milestone.product_code_authorized, false);
assert.equal(milestone.auto_advance, false);
assert.equal(milestone.supervisor_unlock_required, false);

assert.equal(manifest.schema_version, 1);
assert.equal(manifest.program_id, 'linguacafe-recovery-publication-2026-08');
assert.equal(manifest.task_id, 'CFH-02A');
assert.deepEqual(manifest.commit_sequence.map((entry) => entry.commit_id), ['M6A', 'M6B', 'M6C', 'M6D']);
assert.equal(manifest.baseline.direct_file_count, manifest.direct_files.length);
assert.equal(manifest.baseline.candidate_shared_file_count, manifest.shared_files.length);
assert.equal(new Set(manifest.direct_files.map((entry) => entry.path)).size, manifest.direct_files.length);
assert.equal(new Set(manifest.shared_files.map((entry) => entry.path)).size, manifest.shared_files.length);
assert.equal(manifest.decision.status, 'READY_FOR_CFH02B');
assert.equal(manifest.decision.safe_to_start_cfh02b, true);
assert.equal(manifest.decision.product_code_authorized, false);

for (const entry of [...manifest.direct_files, ...manifest.shared_files]) {
    assert.ok(typeof entry.path === 'string' && entry.path.length > 0);
    assert.ok(!entry.path.startsWith('/'));
    assert.ok(!/^[A-Za-z]:[\\/]/.test(entry.path));
    assert.ok(!entry.path.split(/[\\/]/).includes('..'));
}

assert.equal(m6aEvidence.schema_version, 2);
assert.equal(m6aEvidence.task_id, 'CFH-02B-M6A-R2');
assert.equal(m6aEvidence.product_commit, '82b2cf856350561abc54b6e05e51d7a19f120388');
assert.equal(m6aEvidence.browser_channel, 'mcp_chrome');
assert.equal(m6aEvidence.fallback_used, false);
assert.equal(m6aEvidence.environment.app_env, 'testing');
assert.equal(m6aEvidence.environment.testing_database_confirmed, true);
assert.equal(m6aEvidence.environment.fake_mysqldump_confirmed, true);
assert.equal(m6aEvidence.environment.real_database_touched, false);
assert.equal(m6aEvidence.environment.real_restore_executed, false);
assert.equal(m6aEvidence.result.restore_request_count, 0);
assert.equal(m6aEvidence.network.credential_leak_detected, false);
assert.equal(m6aEvidence.conclusion, 'PASS');
assert.ok(Array.isArray(m6aEvidence.steps) && m6aEvidence.steps.length > 0);
assert.ok(Array.isArray(m6aEvidence.mcp.session_or_invocation_ids) && m6aEvidence.mcp.session_or_invocation_ids.length > 0);

assert.equal(m6bEvidence.schema_version, 1);
assert.equal(m6bEvidence.task_id, 'CFH-02B-M6B-R1');
assert.equal(m6bEvidence.product_commit, 'e3619cb33f60ea9a20552038ec1ef16fcf1185db');
assert.equal(m6bEvidence.acceptance_commit, '8125564049957511e776905374fdb9791804248d');
assert.equal(m6bEvidence.browser_channel, 'mcp_chrome');
assert.equal(m6bEvidence.fallback_used, false);
assert.equal(m6bEvidence.desktop.viewport_width, 1440);
assert.equal(m6bEvidence.desktop.viewport_height, 900);
assert.equal(m6bEvidence.desktop.restore_preview_request_count, 0);
assert.equal(m6bEvidence.desktop.preview_token_occurrences, 0);
assert.equal(m6bEvidence.desktop.exact_confirmation_enabled, true);
assert.equal(m6bEvidence.desktop.polling_completed, true);
assert.equal(m6bEvidence.phone.viewport_width, 390);
assert.equal(m6bEvidence.phone.viewport_height, 844);
assert.equal(m6bEvidence.phone.horizontal_overflow, false);
assert.equal(m6bEvidence.phone.dialog_fully_visible, true);
assert.equal(m6bEvidence.phone.refresh_resume_verified, true);
assert.equal(m6bEvidence.tests.passed, 66);
assert.equal(m6bEvidence.tests.failed, 0);
assert.equal(m6bEvidence.tests.assertions, 227);
assert.equal(m6bEvidence.security.app_env_testing, true);
assert.equal(m6bEvidence.security.dedicated_testing_database, true);
assert.equal(m6bEvidence.security.fake_mysqldump, true);
assert.equal(m6bEvidence.security.fake_restore, true);
assert.equal(m6bEvidence.security.real_database_touched, false);
assert.equal(m6bEvidence.security.real_restore_executed, false);
assert.equal(m6bEvidence.security.credentials_recorded, false);
assert.equal(m6bEvidence.security.absolute_local_paths_recorded, false);
assert.equal(m6bEvidence.conclusion, 'PASS');
assert.ok(m6bEvidence.mcp.invocation_ids.every((id) => /^call_[0-9A-Za-z_]+$/.test(id)));
assert.ok(m6bEvidence.steps.some((step) => step.viewport === 'desktop'));
assert.ok(m6bEvidence.steps.some((step) => step.viewport === 'phone'));
assert.ok(m6bEvidence.screenshots.length >= 3);
for (const screenshot of m6bEvidence.screenshots) {
    assert.match(screenshot.sha256, /^[0-9a-f]{64}$/);
    assert.equal(screenshot.stored_outside_repository, true);
}

assert.match(m6aReport, /Status: \*\*ACCEPTED \/ PUBLISHED\*\*/);
assert.match(m6aReport, /M6A_PUBLICATION_ACCEPTED/);
assert.match(m6aReport, /M6A_MCP_ACCEPTED/);
assert.match(m6aReport, /M6A_TRACE_ACCEPTED/);
assert.match(m6bReport, /Status: \*\*ACCEPTED \/ PUBLISHED \/ PRODUCTION CLOSED\*\*/);
assert.match(m6bReport, /66 passed \(227 assertions\)/);
assert.match(m6bReport, /M6B_RESPONSIVE_RESTORE_ACCEPTED/);

assert.match(publicationPlan, /CFH-02_M6_PUBLICATION_ACCEPTED/);
assert.match(implementationPlan, /server-side archive validation with no client preview token/);
assert.match(implementationPlan, /equal access for authenticated\s+users without an admin-role check/);
assert.match(adr0055, /Status: Accepted \/ Implemented \/ Production Closed/);
assert.match(adr0055, /Every authenticated user has the same/);
assert.match(adr0055, /there is no user-visible restore preview/i);

for (const stale of [
    'archive validation and preview token',
    'admin preview/confirm UI',
    'Tokens prove expiry, single use, admin binding',
    'Feature tests prove administrator-only access',
]) {
    assert.ok(!implementationPlan.includes(stale), `当前实施计划不得保留旧 M6B 契约: ${stale}`);
}

for (const file of [
    'docs/testing/cfh-02b-m6a-mcp-chrome-evidence-2026-08-05.json',
    'docs/testing/cfh-02b-m6b-mcp-chrome-evidence-2026-08-05.json',
    'docs/testing/cfh-02b-m6a-publication-acceptance-2026-08-05.md',
    'docs/testing/cfh-02b-m6b-responsive-restore-acceptance-2026-08-05.md',
]) {
    assert.ok(existsSync(join(root, file)), `证据文件存在: ${file}`);
    const text = read(...file.split('/'));
    assert.ok(!/[A-Za-z]:\\(?:Users|Document|Temp)\\/i.test(text), `不得包含本地绝对路径: ${file}`);
    assert.ok(!/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/.test(text), `不得包含账号邮箱: ${file}`);
    assert.ok(!/(password|passwd|api[_-]?key|authorization|bearer|session[_-]?token)\s*[:=]\s*["']?[^\s,"'}]{4,}/i.test(text), `不得包含凭据值: ${file}`);
}

console.log('M6 publication closure guard passed.');
