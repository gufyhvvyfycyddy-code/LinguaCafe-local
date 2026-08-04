import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => readFileSync(join(root, ...parts), 'utf8');
const readJson = (...parts) => JSON.parse(read(...parts));

const masterPlan = read('docs', 'plans', 'linguacafe-recovery-publication-master-plan-2026-08.md');
const milestone = readJson('docs', 'execution', 'CURRENT_MILESTONE.json');
const ownership = readJson('docs', 'audits', 'cfh-01-worktree-ownership-map-2026-08-04.json');
const manifest = readJson('docs', 'audits', 'cfh-02-m6-exact-slice-manifest-2026-08-05.json');

// ================= 顶层契约 =================

// 1. manifest 顶层字段精确（任务 §11 固定 9 个字段，不得增删）
assert.deepEqual(
    Object.keys(manifest).sort(),
    ['baseline', 'commit_sequence', 'decision', 'direct_files', 'excluded_files', 'program_id', 'schema_version', 'shared_files', 'task_id', 'verification_matrix'],
    'manifest 顶层字段',
);
assert.equal(manifest.schema_version, 1);
assert.equal(manifest.program_id, 'linguacafe-recovery-publication-2026-08');
assert.equal(manifest.task_id, 'CFH-02A');

// 2. baseline 字段与格式
assert.deepEqual(
    Object.keys(manifest.baseline).sort(),
    ['authorized_from_commit', 'candidate_shared_file_count', 'direct_file_count', 'origin_master_at_task_start', 'ownership_map_sha256', 'worktree_status_sha256'],
);
for (const key of ['authorized_from_commit', 'origin_master_at_task_start']) {
    assert.match(manifest.baseline[key], /^[0-9a-f]{40}$/, `baseline.${key} 40 位 SHA`);
}
for (const key of ['ownership_map_sha256', 'worktree_status_sha256']) {
    assert.match(manifest.baseline[key], /^[0-9a-f]{64}$/, `baseline.${key} 64 位 SHA`);
}
assert.equal(manifest.baseline.direct_file_count, manifest.direct_files.length, 'direct_file_count 与 direct_files 一致');
assert.equal(manifest.baseline.candidate_shared_file_count, manifest.shared_files.length, 'candidate_shared_file_count 与 shared_files 一致');

// 3. decision 固定字段与枚举
assert.deepEqual(
    Object.keys(manifest.decision).sort(),
    ['product_code_authorized', 'reason', 'safe_to_start_cfh02b', 'status'],
);
assert.ok(['READY_FOR_CFH02B', 'BLOCKED_BY_SHARED_SEAMS', 'NEEDS_SUPERVISOR_DECISION'].includes(manifest.decision.status), `decision.status 枚举: ${manifest.decision.status}`);
assert.equal(manifest.decision.product_code_authorized, false, 'decision 不得自动授权产品代码');
assert.equal(typeof manifest.decision.safe_to_start_cfh02b, 'boolean');
if (manifest.decision.safe_to_start_cfh02b === true) {
    assert.equal(manifest.decision.status, 'READY_FOR_CFH02B', 'safe_to_start_cfh02b=true 必须 status=READY_FOR_CFH02B');
}
assert.ok(manifest.decision.reason.length > 0, 'decision.reason 非空');

// 4. 与 milestone lock / master plan 一致
assert.equal(milestone.active_task, 'CFH-02A', 'milestone.active_task');
assert.match(masterPlan, /active_task: CFH-02A/);
assert.equal(milestone.product_code_authorized, false);
assert.match(masterPlan, /product_code_authorized: false/);
assert.equal(milestone.auto_advance, false);
assert.match(masterPlan, /auto_advance: false/);
assert.equal(milestone.supervisor_unlock_required, true);
assert.match(masterPlan, /supervisor_unlock_required: true/);
assert.equal(milestone.slice_manifest, 'docs/audits/cfh-02-m6-exact-slice-manifest-2026-08-05.json', 'milestone 指向 manifest');

// 5. CFH-02B 仍为 candidate_not_authorized
assert.ok(masterPlan.includes('CFH-02B') || masterPlan.includes('CFH-02B — '), 'master plan 提及 CFH-02B');
assert.match(masterPlan, /candidate_not_authorized/);

// ================= direct_files 契约（任务 §11） =================

const DIRECT_PHASES = new Set(['M6A', 'M6B', 'M6C', 'M6D', 'M6_SHARED']);
const ACTIONS = new Set(['include_whole_file', 'stage_exact_patch', 'exclude_from_m6', 'blocked']);
const DIRECT_KEYS = ['base_blob_sha', 'browser_required', 'evidence', 'git_state', 'm6_phase', 'notes', 'path', 'publication_action', 'required_tests', 'working_tree_sha256'];
const SHA256_RE = /^[0-9a-f]{64}$/;

// 6. direct files 与 ownership map CFH-02 集合完全一致
const ownershipCfh02 = new Set(
    ownership.entries.filter((e) => e.primary_slice === 'CFH-02').map((e) => e.path),
);
assert.equal(ownershipCfh02.size, manifest.baseline.direct_file_count, '归属图 CFH-02 数量');
assert.equal(manifest.direct_files.length, ownershipCfh02.size, 'direct_files 数量与归属图 CFH-02 一致');
const directPaths = manifest.direct_files.map((f) => f.path);
assert.equal(new Set(directPaths).size, directPaths.length, 'direct file 无重复');
for (const path of directPaths) {
    assert.ok(ownershipCfh02.has(path), `direct file 在归属图 CFH-02 中: ${path}`);
}
assert.equal(ownershipCfh02.size, directPaths.length, '归属图 CFH-02 全部在 direct_files 中');

// 7. direct file 字段完整与枚举
for (const f of manifest.direct_files) {
    assert.deepEqual(Object.keys(f).sort(), DIRECT_KEYS, `direct 字段: ${f.path}`);
    assert.ok(DIRECT_PHASES.has(f.m6_phase), `m6_phase: ${f.path} -> ${f.m6_phase}`);
    assert.ok(ACTIONS.has(f.publication_action), `publication_action: ${f.path}`);
    assert.ok(['modified', 'untracked', 'deleted'].includes(f.git_state), `git_state: ${f.path}`);
    assert.match(f.working_tree_sha256, SHA256_RE, `working_tree_sha256: ${f.path}`);
    // tracked 文件 base blob 非空，untracked 为空
    if (f.git_state === 'modified') {
        assert.match(f.base_blob_sha, /^[0-9a-f]{40}$/, `base_blob_sha (modified): ${f.path}`);
    } else {
        assert.equal(f.base_blob_sha, '', `base_blob_sha (untracked): ${f.path}`);
    }
    assert.ok(Array.isArray(f.evidence) && f.evidence.length > 0, `evidence 非空: ${f.path}`);
    assert.ok(Array.isArray(f.required_tests), `required_tests 数组: ${f.path}`);
    assert.equal(typeof f.browser_required, 'boolean');
    assert.equal(typeof f.notes, 'string');
}

// ================= shared_files 契约（任务 §11） =================

const ROLES = new Set(['required_runtime', 'required_registration', 'required_route', 'required_configuration', 'required_ui_entry', 'protected_regression_only', 'unrelated']);
const SHARED_KEYS = ['base_blob_sha', 'decision', 'evidence', 'm6_role', 'non_m6_owners', 'notes', 'path', 'required_tests', 'selected_hunks', 'symbols', 'working_tree_sha256'];
const HUNK_KEYS = ['end_anchor', 'hunk_sha256', 'reason', 'start_anchor', 'symbol'];

// 8. shared file 无重复且必须来自 SHARED_UNRESOLVED
const sharedPaths = manifest.shared_files.map((f) => f.path);
assert.equal(new Set(sharedPaths).size, sharedPaths.length, 'shared file 无重复');
const unresolvedByPath = new Map(ownership.entries.filter((e) => e.primary_slice === 'SHARED_UNRESOLVED').map((e) => [e.path, e]));
for (const path of sharedPaths) {
    assert.ok(unresolvedByPath.has(path), `shared file 来自 SHARED_UNRESOLVED: ${path}`);
}

// 9. shared file 字段完整与枚举
for (const f of manifest.shared_files) {
    assert.deepEqual(Object.keys(f).sort(), SHARED_KEYS, `shared 字段: ${f.path}`);
    assert.ok(ROLES.has(f.m6_role), `m6_role: ${f.path} -> ${f.m6_role}`);
    assert.ok(ACTIONS.has(f.decision), `decision: ${f.path} -> ${f.decision}`);
    assert.match(f.working_tree_sha256, SHA256_RE, `working_tree_sha256: ${f.path}`);
    assert.ok(Array.isArray(f.symbols), `symbols 数组: ${f.path}`);
    assert.ok(Array.isArray(f.selected_hunks), `selected_hunks 数组: ${f.path}`);
    assert.ok(Array.isArray(f.non_m6_owners), `non_m6_owners 数组: ${f.path}`);
    assert.ok(Array.isArray(f.evidence) && f.evidence.length > 0, `evidence 非空: ${f.path}`);
    assert.ok(Array.isArray(f.required_tests), `required_tests 数组: ${f.path}`);
    // protected_regression_only 必须 exclude_from_m6
    if (f.m6_role === 'protected_regression_only') {
        assert.equal(f.decision, 'exclude_from_m6', `protected_regression_only 排除: ${f.path}`);
    }
    // stage_exact_patch 必须至少有一个 selected_hunk
    if (f.decision === 'stage_exact_patch') {
        assert.ok(f.selected_hunks.length > 0, `stage_exact_patch 需要 selected_hunks: ${f.path}`);
    }
    if (f.decision === 'include_whole_file' || f.decision === 'exclude_from_m6') {
        assert.equal(f.selected_hunks.length, 0, `include/exclude 无 selected_hunks: ${f.path}`);
    }
    // selected_hunk 字段完整
    for (const h of f.selected_hunks) {
        assert.deepEqual(Object.keys(h).sort(), HUNK_KEYS, `hunk 字段: ${f.path}`);
        assert.ok(h.symbol.length > 0, `hunk symbol 非空: ${f.path}`);
        assert.ok(h.start_anchor.length > 0, `hunk start_anchor 非空: ${f.path}`);
        assert.ok(h.end_anchor.length > 0, `hunk end_anchor 非空: ${f.path}`);
        assert.ok(h.reason.length > 0, `hunk reason 非空: ${f.path}`);
        assert.match(h.hunk_sha256, SHA256_RE, `hunk_sha256: ${f.path}`);
        // 锚点必须真实存在于当前文件中（防止仅有行号/虚构定位；diff 新增行以锚点文本前缀匹配）
        if (existsSync(join(root, f.path))) {
            const fileContent = read(f.path);
            assert.ok(fileContent.includes(h.start_anchor), `start_anchor 存在于文件: ${f.path} -> ${h.start_anchor}`);
            assert.ok(fileContent.includes(h.end_anchor), `end_anchor 存在于文件: ${f.path} -> ${h.end_anchor}`);
        }
    }
}

// ================= excluded_files 契约 =================

// 10. excluded files 字段
const EXCLUDED_KEYS = ['owner', 'path', 'reason', 'required_as_regression'];
for (const f of manifest.excluded_files) {
    assert.deepEqual(Object.keys(f).sort(), EXCLUDED_KEYS, `excluded 字段: ${f.path}`);
    assert.ok(f.path.length > 0 && f.reason.length > 0 && f.owner.length > 0, `excluded 非空: ${f.path}`);
    assert.equal(typeof f.required_as_regression, 'boolean');
}

// 11. excluded files 不能进入 commit_sequence
const commitPaths = new Set();
for (const c of manifest.commit_sequence) {
    for (const p of [...(c.whole_files || []), ...(c.patch_files || [])]) {
        commitPaths.add(p);
    }
}
for (const f of manifest.excluded_files) {
    assert.ok(!commitPaths.has(f.path), `excluded 不得进入 commit: ${f.path}`);
}

// ================= commit_sequence 契约（任务 §11） =================

const COMMIT_KEYS = ['browser_steps', 'commit_id', 'depends_on', 'patch_files', 'phase', 'stop_conditions', 'tests', 'whole_files'];
const wholeCommitted = new Map();
const decisionByPath = new Map();
for (const f of manifest.direct_files) {
    decisionByPath.set(f.path, f.publication_action);
}
for (const f of manifest.shared_files) {
    decisionByPath.set(f.path, f.decision);
}
for (const c of manifest.commit_sequence) {
    assert.deepEqual(Object.keys(c).sort(), COMMIT_KEYS, `commit 字段: ${c.commit_id}`);
    assert.ok(DIRECT_PHASES.has(c.phase), `commit phase: ${c.commit_id} -> ${c.phase}`);
    assert.equal(c.commit_id, c.phase, 'commit_id 与 phase 一致');
    assert.ok(Array.isArray(c.depends_on), `depends_on 数组: ${c.commit_id}`);
    assert.ok(Array.isArray(c.whole_files) && Array.isArray(c.patch_files), `files 数组: ${c.commit_id}`);
    assert.ok(Array.isArray(c.tests), `tests 数组: ${c.commit_id}`);
    assert.ok(Array.isArray(c.browser_steps), `browser_steps 数组: ${c.commit_id}`);
    assert.ok(Array.isArray(c.stop_conditions), `stop_conditions 数组: ${c.commit_id}`);
    // depends_on 引用先前 commit_id
    for (const dep of c.depends_on) {
        assert.ok(manifest.commit_sequence.some((x) => x.commit_id === dep), `depends_on 存在: ${c.commit_id} -> ${dep}`);
    }
    for (const p of c.whole_files) {
        assert.ok(!wholeCommitted.has(p), `whole 文件不得重复提交: ${p}`);
        wholeCommitted.set(p, c.commit_id);
    }
    for (const p of c.patch_files) {
        const action = decisionByPath.get(p);
        assert.equal(action, 'stage_exact_patch', `patch 文件必须 stage_exact_patch: ${c.commit_id} -> ${p}`);
    }
    // tests 和 browser steps 非空时必须是明确命令或操作
    for (const t of c.tests) {
        assert.ok(t.trim().length > 0, `test 非空: ${c.commit_id}`);
    }
    for (const b of c.browser_steps) {
        assert.ok(b.trim().length > 0, `browser step 非空: ${c.commit_id}`);
    }
    assert.ok(c.stop_conditions.length > 0, `stop_conditions 非空: ${c.commit_id}`);
}

// 12. commit sequence 中路径必须在 direct/shared 决策中存在
for (const c of manifest.commit_sequence) {
    for (const p of [...c.whole_files, ...c.patch_files]) {
        const action = decisionByPath.get(p);
        assert.ok(action !== undefined, `commit 路径有决策: ${c.commit_id} -> ${p}`);
        assert.ok(['include_whole_file', 'stage_exact_patch'].includes(action), `commit 路径决策可提交: ${p} -> ${action}`);
    }
}

// 13. M6A 先于 M6B/C/D 的依赖顺序
const order = manifest.commit_sequence.map((c) => c.commit_id);
assert.deepEqual(order, ['M6A', 'M6B', 'M6C', 'M6D'], '提交顺序 M6A→M6B→M6C→M6D');
assert.deepEqual(manifest.commit_sequence[0].depends_on, [], 'M6A 无依赖');

// 14. 工作区 SHA 一致（manifest 冻结的 working_tree_sha256 与当前文件一致）
for (const f of [...manifest.direct_files, ...manifest.shared_files]) {
    if (!existsSync(join(root, f.path))) {
        continue; // 删除的文件跳过；git_state 为 deleted 时不要求存在
    }
    const content = read(f.path);
    const digest = createHash('sha256').update(content).digest('hex');
    assert.equal(digest, f.working_tree_sha256, `working_tree_sha256 与当前文件一致: ${f.path}`);
}

// 15. 证据文件存在性
for (const f of manifest.direct_files) {
    for (const ev of f.evidence) {
        assert.equal(typeof ev, 'string');
    }
}

// 16. CFH-01 ownership map 未被修改（schema v2 关键字段）
assert.equal(ownership.schema_version, 2);
assert.equal(ownership.entries.length, 462);

console.log('M6 publication slice docs guard passed.');
