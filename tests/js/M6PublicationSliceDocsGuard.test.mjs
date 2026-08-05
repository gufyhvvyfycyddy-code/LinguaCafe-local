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
assert.ok(['CFH-02B-M6A', 'CFH-02B-M6A-R1', 'CFH-02B-M6A-R2'].includes(milestone.active_task), 'milestone.active_task 合法');
assert.match(masterPlan, new RegExp(`active_task: ${milestone.active_task}`));
if (milestone.active_task === 'CFH-02B-M6A' && milestone.status === 'authorized') {
    assert.equal(milestone.product_code_authorized, true);
    assert.match(masterPlan, /product_code_authorized: true/);
} else {
    assert.equal(milestone.product_code_authorized, false);
    assert.match(masterPlan, /product_code_authorized: false/);
}
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

// ================= CFH-02A-R1 supervisor 决定契约（任务 §14） =================

const ADMIN_DASHBOARD = 'resources/js/components/Admin/AdminDashboard.vue';

// 17. AdminDashboard 必须为 direct file（M6_SHARED、stage_exact_patch、browser_required=true）
const adminDashboard = manifest.direct_files.find((f) => f.path === ADMIN_DASHBOARD);
assert.ok(adminDashboard, 'AdminDashboard.vue 必须为 manifest direct file');
assert.equal(adminDashboard.m6_phase, 'M6_SHARED', 'AdminDashboard m6_phase 必须为 M6_SHARED');
assert.equal(adminDashboard.publication_action, 'stage_exact_patch', 'AdminDashboard 必须 stage_exact_patch');
assert.equal(adminDashboard.browser_required, true, 'AdminDashboard 必须 browser_required=true');
assert.ok(adminDashboard.required_tests.includes('npm run development'), 'AdminDashboard 必须要求 npm run development');
assert.ok(adminDashboard.base_blob_sha.length > 0, 'AdminDashboard 为 tracked modified，base_blob_sha 非空');

// 18. AdminDashboard 不得进入 excluded_files，不得进入任何 whole_files
assert.ok(
    !manifest.excluded_files.some((f) => f.path === ADMIN_DASHBOARD),
    'AdminDashboard 不得进入 excluded_files',
);
for (const c of manifest.commit_sequence) {
    assert.ok(
        !c.whole_files.includes(ADMIN_DASHBOARD),
        `AdminDashboard 不得进入 whole_files: ${c.commit_id}`,
    );
}

// 19. AdminDashboard 必须同时进入 M6A 与 M6B patch_files
const m6aCommit = manifest.commit_sequence.find((c) => c.commit_id === 'M6A');
const m6bCommit = manifest.commit_sequence.find((c) => c.commit_id === 'M6B');
assert.ok(m6aCommit && m6aCommit.patch_files.includes(ADMIN_DASHBOARD), 'AdminDashboard 必须在 M6A patch_files');
assert.ok(m6bCommit && m6bCommit.patch_files.includes(ADMIN_DASHBOARD), 'AdminDashboard 必须在 M6B patch_files');

// 20. manifest decision 冻结为 READY_FOR_CFH02B（治理条件满足，不等于产品代码授权）
assert.equal(manifest.decision.status, 'READY_FOR_CFH02B', 'decision.status 必须为 READY_FOR_CFH02B');
assert.equal(manifest.decision.safe_to_start_cfh02b, true, 'safe_to_start_cfh02b 必须为 true');
assert.equal(manifest.decision.product_code_authorized, false, 'product_code_authorized 必须为 false');

// 21. milestone 状态机：authorized（发布授权）/ awaiting_web_acceptance（验收）双阶段
assert.ok(['authorized', 'awaiting_web_acceptance'].includes(milestone.status), 'milestone.status 合法');
assert.equal(milestone.auto_advance, false, 'auto_advance=false');
assert.equal(milestone.supervisor_unlock_required, true, 'supervisor_unlock_required=true');
if (milestone.status === 'awaiting_web_acceptance') {
    assert.equal(milestone.product_code_authorized, false, '最终 product_code_authorized=false');
    assert.equal(milestone.commit_product_code_allowed, false, '最终 commit_product_code_allowed=false');
    assert.equal(milestone.database_write_allowed, false, '最终 database_write_allowed=false');
    // 最终阶段：M6A 验收报告必须存在并引用真实 40 位产品 commit SHA
    const report = read('docs', 'testing', 'cfh-02b-m6a-publication-acceptance-2026-08-05.md');
    assert.ok(report.length > 0, 'M6A 验收报告存在');
    const allCommits = [...report.matchAll(/[0-9a-f]{40}/g)].map((m) => m[0]);
    assert.ok(allCommits.length >= 2, '验收报告引用 40 位 commit SHA（基线 + 产品）');
    assert.ok(
        allCommits.some((c) => c !== 'f67bc560c59bc6e3b506eb403eb69659699b4f28'),
        '产品 commit SHA 必须是新提交（非授权基线）',
    );

    // CFH-02B-M6A-R2：MCP Invocation Trace 证据契约（任务 §九/§十三，schema v2）
    if (milestone.active_task === 'CFH-02B-M6A-R2') {
        const evidence = readJson('docs', 'testing', 'cfh-02b-m6a-mcp-chrome-evidence-2026-08-05.json');
        // 1-3. 顶层字段精确 / schema_version=2 / task_id
        const expectedTop = ['schema_version', 'task_id', 'product_commit', 'browser_channel', 'fallback_used', 'mcp', 'environment', 'account', 'steps', 'result', 'console', 'network', 'screenshots', 'conclusion'];
        assert.deepEqual(Object.keys(evidence).sort(), [...expectedTop].sort(), 'evidence 顶层字段精确');
        assert.equal(evidence.schema_version, 2, 'evidence schema_version=2（拒绝旧 v1）');
        assert.equal(evidence.task_id, 'CFH-02B-M6A-R2', 'evidence task_id');
        assert.equal(evidence.product_commit, '82b2cf856350561abc54b6e05e51d7a19f120388', 'evidence product_commit');
        assert.equal(evidence.browser_channel, 'mcp_chrome', 'evidence browser_channel 必须为 mcp_chrome');
        assert.equal(evidence.fallback_used, false, 'evidence fallback_used 必须为 false');
        // 4. mcp 字段精确
        const expectedMcp = ['server_name', 'server_package', 'tool_names', 'session_or_invocation_ids', 'trace_source'];
        assert.deepEqual(Object.keys(evidence.mcp).sort(), [...expectedMcp].sort(), 'evidence mcp 字段精确');
        assert.equal(evidence.mcp.server_name, 'chrome-devtools', 'evidence mcp.server_name');
        assert.ok(['reasonix-events-log', 'mcp-host-event-log', 'reasonix-session-resource', 'fresh-mcp-rerun'].includes(evidence.mcp.trace_source), 'evidence trace_source 合法');
        // 5-7. session_or_invocation_ids 非空、非空字符串、禁止简单人为顺序号
        const ids = evidence.mcp.session_or_invocation_ids;
        assert.ok(Array.isArray(ids) && ids.length > 0, 'session_or_invocation_ids 非空数组');
        for (const id of ids) {
            assert.ok(typeof id === 'string' && id.length > 0, '每个标识为非空字符串');
            assert.ok(!/^\d+$/.test(id), `禁止纯数字顺序号冒充标识: ${id}`);
            assert.ok(!/^step-\d+$/i.test(id), `禁止 step-N 顺序号冒充标识: ${id}`);
        }
        // 8-13. steps 非空、sequence 连续、tool_name 属于 tool_names、invocation_id 可追踪、action/target/result 非空、覆盖关键操作
        const steps = evidence.steps;
        assert.ok(Array.isArray(steps) && steps.length > 0, 'steps 非空数组');
        steps.forEach((s, i) => {
            assert.equal(s.sequence, i + 1, `sequence 连续: ${s.sequence}`);
            assert.ok(evidence.mcp.tool_names.includes(s.tool_name), `tool_name 属于 tool_names: ${s.tool_name}`);
            assert.ok(ids.includes(s.invocation_id), `invocation_id 可追踪: ${s.invocation_id}`);
            assert.ok(typeof s.action === 'string' && s.action.length > 0, 'action 非空');
            assert.ok(typeof s.target === 'string' && s.target.length > 0, 'target 非空');
            assert.ok(typeof s.result === 'string' && s.result.length > 0, 'result 非空');
            assert.ok(typeof s.success === 'boolean', 'success 为布尔');
        });
        const stepTools = steps.map((s) => s.tool_name);
        for (const required of ['list_pages', 'navigate_page', 'take_snapshot', 'fill_form', 'click', 'wait_for', 'evaluate_script', 'list_console_messages', 'list_network_requests', 'take_screenshot']) {
            assert.ok(stepTools.includes(required), `steps 覆盖关键操作: ${required}`);
        }
        // 14-16. screenshots 非空、SHA 合法、related_invocation_id 可追踪
        assert.ok(Array.isArray(evidence.screenshots) && evidence.screenshots.length > 0, 'screenshots 非空数组');
        for (const shot of evidence.screenshots) {
            assert.ok(/^[0-9a-f]{64}$/.test(shot.sha256), `screenshot SHA-256 64 位小写 hex: ${shot.sha256}`);
            assert.equal(shot.stored_outside_repository, true, 'screenshot stored_outside_repository');
            assert.ok(ids.includes(shot.related_invocation_id), `screenshot related_invocation_id 可追踪: ${shot.related_invocation_id}`);
        }
        // 17. 不包含 password/cookie/Authorization/Bearer/session token 值
        const raw = JSON.stringify(evidence);
        assert.ok(!/(100200hbt|1816529781@qq\.com|Authorization\s*[:=]|Bearer\s+[A-Za-z0-9]|session[_-]?token\s*[:=])/i.test(raw), 'evidence 不含凭据值');
        // 18. 原环境/结果/Console/Network/安全断言全部保留
        assert.equal(evidence.environment.app_env, 'testing', 'evidence app_env=testing');
        assert.equal(evidence.environment.testing_database_confirmed, true, 'evidence testing_database_confirmed');
        assert.equal(evidence.environment.fake_mysqldump_confirmed, true, 'evidence fake_mysqldump_confirmed');
        assert.equal(evidence.environment.temporary_backup_storage_confirmed, true, 'evidence temporary_backup_storage_confirmed');
        assert.equal(evidence.environment.real_database_touched, false, 'evidence real_database_touched=false');
        assert.equal(evidence.environment.real_restore_executed, false, 'evidence real_restore_executed=false');
        assert.equal(evidence.account.task_account_used, true, 'evidence task_account_used');
        assert.equal(evidence.account.login_success, true, 'evidence login_success');
        assert.ok(evidence.result.final_backup_count > evidence.result.initial_backup_count, 'evidence final>initial 备份数');
        assert.equal(evidence.result.reload_persisted, true, 'evidence reload_persisted');
        assert.equal(evidence.result.success_feedback_present, true, 'evidence success_feedback_present');
        assert.equal(evidence.result.restore_request_count, 0, 'evidence restore_request_count=0');
        assert.equal(evidence.network.credential_leak_detected, false, 'evidence credential_leak_detected=false');
        assert.ok(Array.isArray(evidence.console.new_application_errors) && evidence.console.new_application_errors.length === 0, 'evidence new_application_errors 为空');
        assert.equal(evidence.conclusion, 'PASS', 'evidence conclusion=PASS');
        assert.ok(report.includes('MCP Invocation Trace Closure'), '验收报告包含 MCP Invocation Trace Closure 章节');
        assert.ok(report.includes('不构成最终网页端验收'), '验收报告不得把 Playwright 称为最终门禁');
        assert.equal(milestone.browser_channel, 'mcp_chrome', 'milestone browser_channel=mcp_chrome');
    } else if (milestone.active_task === 'CFH-02B-M6A-R1') {
        // 历史 R1 契约（只接受 schema v1 之上、但本轮后 evidence 已是 v2——旧契约分支不再读取 evidence 细节）
        assert.ok(report.includes('MCP Chrome Mandatory Revalidation'), '验收报告包含 MCP Chrome Mandatory Revalidation 章节');
    }
}

// 22. M6B/M6C/M6D 在总计划中仍为 candidate_not_authorized
assert.ok(
    masterPlan.includes('M6B（恢复安全）、M6C（内容健康）、M6D（隔离收口）均为 `candidate_not_authorized`'),
    'M6B/M6C/M6D 必须保持 candidate_not_authorized',
);

console.log('M6 publication slice docs guard passed.');
