import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');

// ================= 文档契约（1-6、26-27） =================
const masterPlan = read('docs', 'plans', 'linguacafe-recovery-publication-master-plan-2026-08.md');
const milestone = JSON.parse(read('docs', 'execution', 'CURRENT_MILESTONE.json'));
const handoff = read('docs', 'plans', 'codex-final-handoff-2026-08-04.md');

// 1. 总计划 front matter 与里程碑锁一致（active_task 动态比较，不再硬编码）
assert.match(masterPlan, new RegExp(`active_task: ${milestone.active_task}`));
// 2. active_task 为当前授权任务（由 milestone 锁提供）
assert.equal(milestone.schema_version, 2);
assert.ok(milestone.active_task.length > 0);
assert.ok(milestone.active_task.startsWith('CFH-'), `active_task 前缀: ${milestone.active_task}`);
// 3. product_code_authorized 状态机：仅授权阶段（CFH-02B-M6A / CFH-02B-M6B）允许 true
const PRODUCT_AUTHORIZED_TASKS = ['CFH-02B-M6A', 'CFH-02B-M6B'];
if (PRODUCT_AUTHORIZED_TASKS.includes(milestone.active_task) && milestone.status === 'authorized') {
    assert.equal(milestone.product_code_authorized, true);
    assert.match(masterPlan, /product_code_authorized: true/);
} else {
    assert.equal(milestone.product_code_authorized, false);
    assert.match(masterPlan, /product_code_authorized: false/);
}
// 4. auto_advance 为 false
assert.equal(milestone.auto_advance, false);
assert.match(masterPlan, /auto_advance: false/);
// 5. supervisor_unlock_required 为 true
assert.equal(milestone.supervisor_unlock_required, true);
assert.match(masterPlan, /supervisor_unlock_required: true/);
// 6. 候选任务全部仍为 candidate_not_authorized
assert.ok(masterPlan.includes('status: candidate_not_authorized'));
assert.ok(!masterPlan.includes('status: authorized'));
// 26. 恢复计划不得再把旧提交称为永久的"GitHub 最新正式基线"
assert.ok(!masterPlan.includes('GitHub 最新正式基线'));
assert.ok(masterPlan.includes('不永久写死'));
// 3. 里程碑状态机：status 只允许 authorized / awaiting_web_acceptance，不得 accepted
assert.ok(milestone.status === 'authorized' || milestone.status === 'awaiting_web_acceptance');
assert.ok(milestone.status !== 'accepted');
// 3a. CFH-02A-R1（历史任务）最终提交状态必须为 awaiting_web_acceptance
if (milestone.active_task === 'CFH-02A-R1') {
    assert.equal(milestone.status, 'awaiting_web_acceptance', 'CFH-02A-R1 提交时 status 必须为 awaiting_web_acceptance');
}
// 3c. CFH-02B-M6B / CFH-02B-M6B-R1 授权/验收双阶段（equal-privilege、no-preview、responsive web）
if (['CFH-02B-M6B', 'CFH-02B-M6B-R1'].includes(milestone.active_task)) {
    assert.equal(milestone.auto_advance, false);
    assert.equal(milestone.supervisor_unlock_required, true);
    assert.equal(milestone.migration_execution_allowed, false, 'CFH-02B-M6B 不执行 migration');
    assert.equal(milestone.browser_channel, 'mcp_chrome', 'CFH-02B-M6B 强制 browser_channel=mcp_chrome');
    assert.equal(milestone.device_required, false, 'CFH-02B-M6B 不要求原生设备（响应式网页）');
    if (milestone.status === 'authorized' && milestone.active_task === 'CFH-02B-M6B') {
        assert.equal(milestone.product_code_authorized, true, 'CFH-02B-M6B 授权阶段 product_code_authorized=true');
        assert.equal(milestone.commit_product_code_allowed, true, 'CFH-02B-M6B 授权阶段 commit_product_code_allowed=true');
        assert.equal(milestone.database_write_allowed, true, 'CFH-02B-M6B 授权阶段 database_write_allowed=true（仅 testing 隔离）');
        assert.equal(milestone.database_write_scope, 'isolated_testing_database_and_temporary_restore_storage_only', 'CFH-02B-M6B 数据库写入范围仅 testing 隔离');
        assert.equal(milestone.browser_required, true, 'CFH-02B-M6B 授权阶段 browser_required=true');
        assert.ok(masterPlan.includes('CFH-02B-M6B — Rework And Publish Single-Owner Restore For Responsive Web'), 'master plan 当前任务为 CFH-02B-M6B');
        assert.ok(masterPlan.includes('equal-privilege'), 'master plan 冻结 equal-privilege');
        assert.ok(masterPlan.includes('no user-visible preview'), 'master plan 冻结 no user-visible preview');
        assert.ok(masterPlan.includes('exact RESTORE input + final click'), 'master plan 冻结 exact RESTORE input + final click');
        assert.ok(masterPlan.includes('desktop and phone responsive web'), 'master plan 冻结 desktop and phone responsive web');
        assert.ok(masterPlan.includes('internal safety checks preserved'), 'master plan 冻结 internal safety checks preserved');
        assert.ok(masterPlan.includes('M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`'), 'M6C/M6D 必须保持 candidate_not_authorized');
    } else if (milestone.status === 'authorized' && milestone.active_task === 'CFH-02B-M6B-R1') {
        // R1 执行阶段：仅证据与治理收口，不授权产品代码
        assert.equal(milestone.product_code_authorized, false, 'CFH-02B-M6B-R1 授权阶段 product_code_authorized=false');
        assert.equal(milestone.commit_product_code_allowed, false, 'CFH-02B-M6B-R1 授权阶段 commit_product_code_allowed=false');
    } else {
        assert.equal(milestone.status, 'awaiting_web_acceptance');
        assert.equal(milestone.product_code_authorized, false, '最终阶段 product_code_authorized=false');
        assert.equal(milestone.commit_product_code_allowed, false, '最终阶段 commit_product_code_allowed=false');
        assert.equal(milestone.database_write_allowed, false, '最终阶段 database_write_allowed=false');
        assert.equal(milestone.browser_required, false, '最终阶段 browser_required=false');
        if (milestone.active_task === 'CFH-02B-M6B-R1') {
            assert.ok(masterPlan.includes('CFH-02B-M6B-R1 — Close MCP Trace And Governance Evidence'), 'master plan 当前任务为 CFH-02B-M6B-R1');
            assert.ok(masterPlan.includes('阶段性接受'), 'master plan 记录网页端 GPT 阶段性接受');
            assert.ok(masterPlan.includes('Incomplete'), 'master plan 记录 M6B 整体仍 Incomplete');
            assert.ok(masterPlan.includes('M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`'), 'M6C/M6D 未授权');
        }
    }
}
if (['CFH-02B-M6A', 'CFH-02B-M6A-R1', 'CFH-02B-M6A-R2'].includes(milestone.active_task)) {
    assert.equal(milestone.auto_advance, false);
    assert.equal(milestone.supervisor_unlock_required, true);
    if (milestone.status === 'authorized') {
        if (milestone.active_task === 'CFH-02B-M6A-R2') {
            // R2 仅从日志恢复追踪标识（fresh rerun 时 browser/db 才为 true）
            assert.equal(milestone.product_code_authorized, false, 'CFH-02B-M6A-R2 授权阶段 product_code_authorized=false');
            assert.equal(milestone.commit_product_code_allowed, false, 'CFH-02B-M6A-R2 授权阶段 commit_product_code_allowed=false');
            assert.equal(milestone.database_write_allowed, false, 'CFH-02B-M6A-R2 仅日志恢复 database_write_allowed=false');
            assert.equal(milestone.browser_required, false, 'CFH-02B-M6A-R2 仅日志恢复 browser_required=false');
            assert.equal(milestone.browser_channel, 'mcp_chrome', 'CFH-02B-M6A-R2 强制 browser_channel=mcp_chrome');
        } else if (milestone.active_task === 'CFH-02B-M6A-R1') {
            // R1 只做验收，不发布产品代码
            assert.equal(milestone.product_code_authorized, false, 'CFH-02B-M6A-R1 授权阶段 product_code_authorized=false');
            assert.equal(milestone.commit_product_code_allowed, false, 'CFH-02B-M6A-R1 授权阶段 commit_product_code_allowed=false');
            assert.equal(milestone.database_write_allowed, true, 'CFH-02B-M6A-R1 授权阶段 database_write_allowed=true（仅 testing 隔离）');
            assert.equal(milestone.browser_required, true, 'CFH-02B-M6A-R1 授权阶段 browser_required=true');
            assert.equal(milestone.browser_channel, 'mcp_chrome', 'CFH-02B-M6A-R1 强制 browser_channel=mcp_chrome');
        } else {
            assert.equal(milestone.product_code_authorized, true, 'CFH-02B-M6A 授权阶段 product_code_authorized=true');
            assert.equal(milestone.commit_product_code_allowed, true, 'CFH-02B-M6A 授权阶段 commit_product_code_allowed=true');
            assert.equal(milestone.database_write_allowed, true, 'CFH-02B-M6A 授权阶段 database_write_allowed=true（仅 testing 隔离）');
            assert.equal(milestone.browser_required, true, 'CFH-02B-M6A 授权阶段 browser_required=true');
        }
    } else {
        assert.equal(milestone.status, 'awaiting_web_acceptance');
        assert.equal(milestone.product_code_authorized, false, '最终阶段 product_code_authorized=false');
        assert.equal(milestone.commit_product_code_allowed, false, '最终阶段 commit_product_code_allowed=false');
        assert.equal(milestone.database_write_allowed, false, '最终阶段 database_write_allowed=false');
        assert.equal(milestone.browser_required, false, '最终阶段 browser_required=false');
    }
    // M6B/M6C/M6D 仍未授权（仅 M6A 授权）
    assert.ok(masterPlan.includes('M6B` — 恢复安全（restore preview/confirm/polling；依赖 M6A 发布验收与网页端 GPT 单独授权）'), 'M6B 必须保持 candidate_not_authorized');
    assert.ok(masterPlan.includes('M6C` — 内容健康（依赖 M6A/M6B 发布验收与单独授权）'), 'M6C 必须保持 candidate_not_authorized');
    assert.ok(masterPlan.includes('M6D` — 隔离收口（依赖 M6A/M6B/M6C 发布验收与单独授权）'), 'M6D 必须保持 candidate_not_authorized');
}
// 29. 权威 handoff 存在且 master plan 声明指向它
assert.ok(handoff.includes('HANDOFF_READY_WITH_BLOCKERS'));
assert.ok(masterPlan.includes('authoritative_handoff: docs/plans/codex-final-handoff-2026-08-04.md'));
// 30. 状态词汇保留（AGENTS.md §1 权威层级中的治理词汇）
assert.ok(masterPlan.includes('LOCAL_UNATTRIBUTED'));
assert.ok(masterPlan.includes('PUSHED_AWAITING_ACCEPTANCE'));
assert.ok(masterPlan.includes('BLOCKED_EXTERNAL'));
assert.ok(masterPlan.includes('DO_NOT_COMMIT'));
assert.ok(masterPlan.includes('文件具有多个无法分离的所有者'));
assert.ok(masterPlan.includes('唯一机器可读依据'));

// ================= 归属图契约（7-25） =================
const ownership = JSON.parse(read('docs', 'audits', 'cfh-01-worktree-ownership-map-2026-08-04.json'));

// 7. schema_version 为 2
assert.equal(ownership.schema_version, 2);
// 8. 顶层字段精确，无多余或缺失字段
assert.deepEqual(Object.keys(ownership).sort(), ['baseline', 'entries', 'purpose', 'schema_version', 'summary']);
assert.deepEqual(Object.keys(ownership.baseline).sort(), ['authorized_from_commit', 'branch', 'conflicts', 'counts', 'origin_master_at_task_start', 'status_snapshot_sha256']);
assert.deepEqual(Object.keys(ownership.summary).sort(), ['blocked_external', 'by_primary_slice', 'do_not_commit', 'needs_android', 'needs_browser', 'needs_ios', 'needs_tests', 'represented_paths', 'resolved_primary_slice', 'unresolved_primary_slice']);
assert.deepEqual(Object.keys(ownership.baseline.counts).sort(), ['total', 'tracked_deleted', 'tracked_modified', 'untracked']);

const GIT_STATES = new Set(['modified', 'deleted', 'untracked']);
const TYPES = new Set(['product_source', 'test', 'documentation', 'migration', 'generated', 'local_agent_metadata', 'unknown']);
const SLICES = new Set(['CFH-02', 'CFH-03-M1', 'CFH-03-M2', 'CFH-03-M3', 'CFH-03-M4', 'CFH-03-M5', 'CFH-04-M7', 'CFH-04-M8', 'CFH-05-M9', 'M10', 'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17-WEB-ANDROID', 'M18-WEB-ANDROID', 'GOVERNANCE', 'SHARED_UNRESOLVED', 'DO_NOT_COMMIT', 'local_agent_metadata']);
const READINESS = new Set(['ready_for_verification', 'needs_tests', 'needs_browser', 'needs_android', 'needs_ios', 'blocked_external', 'unresolved', 'do_not_commit']);
const KINDS = new Set(['diff', 'call_chain', 'migration_dependency', 'acceptance_report', 'milestone_plan', 'test', 'repository_policy']);
const GROUPS = new Set(['CFH-02-M6A', 'CFH-02-M6B', 'CFH-02-M6C', 'CFH-02-M6D', 'CFH-02', 'CFH-03-M1', 'CFH-03-M2', 'CFH-03-M3', 'CFH-03-M4', 'CFH-03-M5', 'CFH-04-M7', 'CFH-04-M8', 'CFH-05-M9', 'M10', 'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17-WEB-ANDROID', 'M18-WEB-ANDROID', 'GOVERNANCE', 'unassigned', 'do-not-commit']);
const ENTRY_KEYS = ['asset_type', 'commit_group', 'evidence', 'git_state', 'notes', 'path', 'primary_slice', 'readiness', 'related_milestones', 'shared_with'];
const EVIDENCE_KEYS = ['detail', 'kind', 'source'];

// 11. 462 个路径无重复
assert.equal(ownership.entries.length, 462);
const seen = new Set();
for (const entry of ownership.entries) {
    // 9. entry 字段精确
    assert.deepEqual(Object.keys(entry).sort(), ENTRY_KEYS, `entry 字段: ${entry.path}`);
    // 10. 禁止旧 status 字段
    assert.ok(!Object.prototype.hasOwnProperty.call(entry, 'status'), `旧 status 字段: ${entry.path}`);
    assert.equal(typeof entry.path, 'string');
    assert.ok(!seen.has(entry.path), `重复路径: ${entry.path}`);
    seen.add(entry.path);
    // 12. 路径均为仓库相对路径
    assert.ok(!entry.path.startsWith('/'), `绝对路径: ${entry.path}`);
    // 13. 路径不得含盘符绝对路径、.. 路径逃逸、.env、凭据值
    assert.ok(!/^[A-Za-z]:[\\/]/.test(entry.path), `盘符路径: ${entry.path}`);
    assert.ok(!entry.path.split(/[\\/]/).includes('..'), `路径逃逸: ${entry.path}`);
    assert.ok(!entry.path.includes('.env'), `.env 引用: ${entry.path}`);
    assert.ok(!/(password|secret|api[_-]?key|bearer\s|token\s*=\s*[A-Za-z0-9]{16,})/i.test(entry.path), `疑似凭据: ${entry.path}`);
    // 14. 所有枚举合法
    assert.ok(GIT_STATES.has(entry.git_state), `git_state: ${entry.path}`);
    assert.ok(TYPES.has(entry.asset_type), `asset_type: ${entry.path}`);
    assert.ok(SLICES.has(entry.primary_slice), `primary_slice: ${entry.path}`);
    assert.ok(READINESS.has(entry.readiness), `readiness: ${entry.path}`);
    assert.ok(Array.isArray(entry.related_milestones) && entry.related_milestones.every((m) => /^M([0-9]|1[0-8])$/.test(m)), `related_milestones: ${entry.path}`);
    assert.ok(Array.isArray(entry.shared_with) && entry.shared_with.every((s) => SLICES.has(s)), `shared_with: ${entry.path}`);
    assert.ok(GROUPS.has(entry.commit_group), `commit_group: ${entry.path} -> ${entry.commit_group}`);
    assert.equal(typeof entry.notes, 'string');
    // 15. evidence 为非空数组
    assert.ok(Array.isArray(entry.evidence) && entry.evidence.length > 0, `evidence 非空: ${entry.path}`);
    for (const ev of entry.evidence) {
        // 16. evidence 每项字段、kind 和 source 合法
        assert.deepEqual(Object.keys(ev).sort(), EVIDENCE_KEYS, `evidence 字段: ${entry.path}`);
        assert.ok(KINDS.has(ev.kind), `evidence kind: ${entry.path}`);
        assert.equal(typeof ev.source, 'string');
        assert.equal(typeof ev.detail, 'string');
        // 17. evidence source 不得是空值或模糊标签
        assert.ok(ev.source.length > 0, `evidence source 空: ${entry.path}`);
        assert.ok(ev.source === 'git-diff' || (ev.source.length > 0 && !ev.source.startsWith('/') && !/^[A-Za-z]:[\\/]/.test(ev.source) && !/\s/.test(ev.source) && !ev.source.split(/[\\/]/).includes('..')), `evidence source 模糊: ${entry.path} -> ${ev.source}`);
        assert.ok(ev.detail.length > 0, `evidence detail 空: ${entry.path}`);
        // 31. evidence source 必须真实存在（git-diff 除外）
        if (ev.source !== 'git-diff') {
            assert.ok(existsSync(join(root, ev.source)), `evidence source 不存在: ${entry.path} -> ${ev.source}`);
        }
    }
    // 18. resolved entry 的 commit_group 不得为 unassigned
    if (entry.primary_slice !== 'SHARED_UNRESOLVED' && entry.primary_slice !== 'DO_NOT_COMMIT' && entry.primary_slice !== 'local_agent_metadata') {
        assert.notEqual(entry.commit_group, 'unassigned', `resolved group: ${entry.path}`);
        assert.notEqual(entry.commit_group, 'do-not-commit', `resolved group: ${entry.path}`);
    }
    // 19. SHARED_UNRESOLVED 约束
    if (entry.primary_slice === 'SHARED_UNRESOLVED') {
        assert.equal(entry.readiness, 'unresolved', `unresolved readiness: ${entry.path}`);
        assert.equal(entry.commit_group, 'unassigned', `unresolved group: ${entry.path}`);
        assert.ok(entry.shared_with.length > 0, `unresolved shared_with 非空: ${entry.path}`);
        assert.ok(entry.notes.length > 0, `unresolved notes 非空: ${entry.path}`);
    }
    // 20. DO_NOT_COMMIT / local_agent_metadata 约束
    if (entry.primary_slice === 'DO_NOT_COMMIT' || entry.primary_slice === 'local_agent_metadata') {
        assert.equal(entry.readiness, 'do_not_commit', `dnc readiness: ${entry.path}`);
        assert.equal(entry.commit_group, 'do-not-commit', `dnc group: ${entry.path}`);
    }
    // 21. migration 约束
    if (entry.asset_type === 'migration') {
        assert.ok(entry.notes.length > 0, `migration notes: ${entry.path}`);
        assert.ok(entry.related_milestones.length > 0, `migration milestones: ${entry.path}`);
    }
    // 22. deleted 文件必须有说明替代或删除依据
    if (entry.git_state === 'deleted') {
        assert.ok(entry.notes.length > 0, `deleted notes: ${entry.path}`);
    }
}

// 23. summary 必须从 entries 重新计算并完全一致
const recomputed = ownership.entries.reduce((acc, e) => {
    acc.by_primary_slice[e.primary_slice] = (acc.by_primary_slice[e.primary_slice] || 0) + 1;
    acc.readiness[e.readiness] = (acc.readiness[e.readiness] || 0) + 1;
    return acc;
}, { by_primary_slice: {}, readiness: {} });
assert.deepEqual(recomputed.by_primary_slice, ownership.summary.by_primary_slice);
assert.equal(ownership.summary.needs_tests, recomputed.readiness.needs_tests || 0);
assert.equal(ownership.summary.needs_browser, recomputed.readiness.needs_browser || 0);
assert.equal(ownership.summary.needs_android, recomputed.readiness.needs_android || 0);
assert.equal(ownership.summary.needs_ios, recomputed.readiness.needs_ios || 0);
assert.equal(ownership.summary.blocked_external, recomputed.readiness.blocked_external || 0);
assert.equal(ownership.summary.resolved_primary_slice, ownership.entries.filter((e) => e.primary_slice !== 'SHARED_UNRESOLVED' && e.primary_slice !== 'DO_NOT_COMMIT' && e.primary_slice !== 'local_agent_metadata').length);
assert.equal(ownership.summary.unresolved_primary_slice, ownership.entries.filter((e) => e.primary_slice === 'SHARED_UNRESOLVED').length);
assert.equal(ownership.summary.do_not_commit, ownership.entries.filter((e) => e.primary_slice === 'DO_NOT_COMMIT' || e.primary_slice === 'local_agent_metadata').length);
// 24. represented_paths 等于 entries.length
assert.equal(ownership.summary.represented_paths, ownership.entries.length);
// 25. total 等于 modified + deleted + untracked
const counts = ownership.baseline.counts;
assert.equal(counts.total, counts.tracked_modified + counts.tracked_deleted + counts.untracked);
assert.equal(ownership.entries.length, counts.total);
assert.deepEqual(ownership.baseline.conflicts, []);

// 32. AdminDashboard.vue 归属由 supervisor 决定为 CFH-02（M6_SHARED），不得再为 M13
const adminDashboard = ownership.entries.find((e) => e.path === 'resources/js/components/Admin/AdminDashboard.vue');
assert.ok(adminDashboard, 'AdminDashboard.vue 必须存在于归属图');
assert.equal(adminDashboard.primary_slice, 'CFH-02', 'AdminDashboard primary_slice 必须为 CFH-02');
assert.notEqual(adminDashboard.primary_slice, 'M13', 'AdminDashboard 不得再 primary_slice=M13');
assert.deepEqual(adminDashboard.related_milestones, ['M6'], 'AdminDashboard related_milestones 必须为 [M6]');
assert.equal(adminDashboard.commit_group, 'CFH-02', 'AdminDashboard commit_group 必须为 CFH-02');
assert.equal(adminDashboard.readiness, 'needs_browser', 'AdminDashboard readiness 必须为 needs_browser');

// 33. M6C/M6D 继续 candidate_not_authorized（M6B 为当前任务；§4 明确表述，§5 登记队列）
if (['CFH-02B-M6B', 'CFH-02B-M6B-R1'].includes(milestone.active_task)) {
    assert.ok(masterPlan.includes('M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`'), 'master plan §4 中 M6C/M6D 必须保持 candidate_not_authorized');
} else {
    assert.ok(masterPlan.includes('M6B（恢复安全）、M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`'), 'master plan §4 中 M6B/M6C/M6D 必须保持 candidate_not_authorized');
}
assert.ok(masterPlan.includes('每项状态均为：`status: candidate_not_authorized`'), 'master plan §5 候选队列保持 candidate_not_authorized');
assert.ok(!masterPlan.includes('status: authorized'), 'master plan 不得出现 status: authorized');

console.log('Recovery publication workflow docs guard (v2 contract) passed.');
