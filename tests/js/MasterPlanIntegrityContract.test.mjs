import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const absolute = relativePath => path.join(root, relativePath);
const read = relativePath => fs.readFileSync(absolute(relativePath), 'utf8');
const section = (source, startHeading, nextHeading) => {
    const start = source.indexOf(startHeading);
    assert.notEqual(start, -1, `missing section: ${startHeading}`);
    const end = nextHeading ? source.indexOf(nextHeading, start + startHeading.length) : source.length;
    assert.notEqual(end, -1, `missing next section: ${nextHeading}`);
    return source.slice(start, end);
};

const master = read('docs/plans/linguacafe-master-plan.md');
const roadmap = read('docs/plans/anki-aligned-product-and-architecture-roadmap.md');
const product = read('docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md');
const index = read('docs/DOCUMENTATION_INDEX.md');
const currentContext = read('docs/CURRENT_AI_CONTEXT.md');
const collaboration = read('docs/plans/vibe-coding-collaboration-rules.md');
const m7Acceptance = read('docs/testing/m7-android-connected-mvp-acceptance-2026-08-01.md');
const m9Acceptance = read('docs/testing/m9-ios-mvp-release-acceptance-2026-08-01.md');
const ankiComparison = read('docs/architecture/anki-function-and-architecture-sales-comparison-2026-07-23.md');
const milestone = JSON.parse(read('docs/execution/CURRENT_MILESTONE.json'));

const authority = section(master, '> **Current authority — 2026-08-06**', '> **Authoritative Custom Study status');
const openWork = section(master, '## 4. Open Work Registry（当前运行、维护与外部能力账本）', '## 5. 颜色语义规则');

assert.match(authority, /Recovery\/publication is closed/);
assert.match(authority, /five operational or externally deferred items/);
assert.match(authority, /Frozen Product Gate/);
assert.match(authority, /PD-012.*Product Frozen \/ Architecture Spec Allowed \/ Product Code Not Authorized/);
assert.match(authority, /current measurements and hotspot evidence live in/i);
assert.doesNotMatch(authority, /28 production files|10 over 1,000|668 lines|6\.0\/10/);
assert.match(authority, /Custom Study 1A and Card Marker \+ Custom Study 1B are Accepted \/ Production Closed/);

const openItems = [
    'M9 iOS sync、编译、签名、设备、TestFlight 与 App Store 证据',
    'Runtime AI provider 激活',
    '本地体验与 Bug 维护账本',
    'Reasonix / DevSpace / 浏览器监督工具链可靠性',
    '旧电脑或其他仓库复现',
];
for (const term of openItems) assert.ok(openWork.includes(term), `open registry missing: ${term}`);
for (const status of [
    'Deferred / Not Complete',
    'Environment Gate / Explicit Authorization Required',
    'Planned / Not Yet Authorized',
    'Workaround Active / Root Fix Open',
    'Deferred / Unverified',
]) assert.ok(openWork.includes(status), `open registry missing status: ${status}`);

assert.match(openWork, /PD-012“阅读中直接刷词义卡 V1”已完成产品冻结/);
assert.match(openWork, /product_code_authorized=false/);
assert.match(openWork, /PD-013 的 M1“下一里程碑”措辞只属于 2026-07-28 历史路线基线/);
assert.match(openWork, /M1–M8、M10–M16、M17 Web slice、M18 共享实现与 Web\/Android 证据/);
assert.match(openWork, /M17 的 Android Haptics\/Local Notifications 事实由 M7 平台验收持有/);
assert.match(openWork, /ignored iOS generated public 仍为旧 bundle 且含 sourcemap/);
assert.match(openWork, /它们不是开放任务/);
for (const staleRow of [
    'Settings architecture convergence | Completed',
    'Browser / ReviewCardManage architecture convergence | Completed',
    'Card Marker + Custom Study 1B | Accepted',
    'Reviewer architecture convergence | Accepted',
    'AI Study Card service convergence | Completed',
    'Automatic Backup / Restore V1 |',
    'WordSense Tag V1 |',
]) assert.ok(!openWork.includes(staleRow), `closed/planning row must not remain active: ${staleRow}`);

const orderedRoadmapTerms = [
    'Phase 1：Settings 架构收敛',
    'Phase 2：Preset V1',
    'Phase 3：Browser / ReviewCardManage 架构收敛',
    'Phase 4：Card Marker + Custom Study 1B',
    'Phase 5：Reviewer 架构收敛',
    'Phase 6：Reader UI 与阅读架构治理',
    'Phase 7：AI Study Card service 收敛与真实 provider',
    'Phase 8：已接受的新产品规划（尚未授权实现）',
];
let previous = -1;
for (const term of orderedRoadmapTerms) {
    const current = roadmap.indexOf(term);
    assert.ok(current > previous, `roadmap term must exist in order: ${term}`);
    previous = current;
}
assert.match(roadmap, /自动备份与恢复/);
assert.match(roadmap, /WordSense Tag/);
assert.match(roadmap, /固定模板 `.apkg` 内容卡导出/);
assert.match(roadmap, /受控插件接口/);
assert.match(roadmap, /正式评分语义待讨论/);
assert.match(roadmap, /PD-012“阅读中直接刷词义卡 V1”已完成产品冻结/);
assert.match(roadmap, /当前只允许 Architecture Spec \/ ADR \/ Harness 迁移设计/);
assert.doesNotMatch(roadmap, /尚未冻结：[^\n]*阅读中直接评分/);

for (let i = 1; i <= 13; i++) assert.match(product, new RegExp(`PD-${String(i).padStart(3, '0')}`));
for (let i = 1; i <= 5; i++) assert.match(product, new RegExp(`DISC-${String(i).padStart(3, '0')}`));
assert.match(product, /PD-012 阅读中直接刷词义卡 V1/);
assert.match(product, /状态：已冻结，可进入架构 Spec 与分阶段实现；尚未授权修改业务代码/);
assert.match(product, /2026-07-28 当时唯一推荐的下一技术里程碑/);
assert.match(product, /当前不得再把 M1 写成下一任务/);
assert.match(product, /至少分四轮/);
assert.match(product, /不代表任何代码实现已经授权/);
assert.match(product, /现有 Harness 与新提议冲突时，先保留 Harness/);

assert.equal(milestone.active_task, 'NONE');
assert.deepEqual(milestone.allowed_work, []);
assert.equal(milestone.product_code_authorized, false);
assert.equal(milestone.auto_advance, false);
assert.equal(milestone.database_write_allowed, false);
assert.equal(milestone.commit_product_code_allowed, false);

assert.match(currentContext, /执行新任务仍必须重新运行 Git preflight/);
assert.match(currentContext, /active_task=NONE/);
assert.match(currentContext, /allowed_work=\[\]/);
assert.match(currentContext, /product_code_authorized=false/);
assert.match(currentContext, /auto_advance=false/);
assert.match(currentContext, /2026-07-23 历史对比快照/);
assert.doesNotMatch(currentContext, /下表是 2026-08-06 提交前的规模快照/);
assert.match(currentContext, /2026-08-06[\s\S]*debug APK[\s\S]*没有最新设备复验/);
assert.match(currentContext, /不代表 release\/AAB\/签名\/Play Store/);
assert.match(currentContext, /ignored iOS generated public 仍是旧 bundle、含 sourcemap/);
assert.match(currentContext, /Xcode、签名、[\s\S]*TestFlight 与 App Store 能力簇仍 `Not Complete`/);

assert.match(m7Acceptance, /that exact rebuilt\s+artifact was not reinstalled or re-run through the native UI matrix/i);
assert.match(m7Acceptance, /must not be described as device-revalidated, release-signed or\s+store-ready/i);
assert.match(m9Acceptance, /ignored generated directory `mobile\/ios\/App\/App\/public\/\*\*` is not part of\s+that commit and is currently stale/i);
assert.match(m9Acceptance, /contains four sourcemaps/i);
assert.match(m9Acceptance, /must run controlled\s+`cap sync ios`/i);
assert.match(m9Acceptance, /not marked Accepted, Closed or Complete/i);

assert.match(index, /confirmed-product-decisions-and-discussion-roadmap-2026-07-23\.md/);
assert.match(index, /2026-07-23 历史代码、文档与 Bug 架构快照/);
assert.match(index, /2026-07-23 Anki 功能与架构历史通俗对比/);
assert.match(index, /2026-07-23 原项目代码、测试与架构历史对比/);
assert.match(index, /CurrentExecutionWorkflowDocsGuard\.test\.mjs/);
assert.match(ankiComparison, /Historical Snapshot \/ 2026-07-23/);
assert.match(ankiComparison, /后续已由 PD-012 冻结/);
assert.match(ankiComparison, /业务代码尚未授权/);

const indexedPaths = [...index.matchAll(/`((?:docs|tests|app|resources)\/[^`]+)`/g)]
    .map(match => match[1])
    .filter(value => !value.includes('*'));
for (const relativePath of new Set(indexedPaths)) {
    assert.ok(fs.existsSync(absolute(relativePath)), `index route missing in clean checkout: ${relativePath}`);
}

assert.match(collaboration, /本地 Codex 直接处理/);
assert.match(collaboration, /网页端 GPT 通过 DevSpace 直接处理/);
assert.match(collaboration, /优先使用 FastCtx/);
assert.match(collaboration, /Codex\+\+.*未来方向/);
assert.match(collaboration, /auto_advance: false/);
assert.doesNotMatch(collaboration, /当前任务由一个主执行 Agent 对结果负责/);

const publicGovernanceFiles = [
    'docs/CURRENT_AI_CONTEXT.md',
    'docs/DOCUMENTATION_INDEX.md',
    'docs/plans/linguacafe-master-plan.md',
    'docs/plans/anki-aligned-product-and-architecture-roadmap.md',
    'docs/plans/local-experience-bug-optimization-ledger-2026-07-23.md',
    'docs/plans/reasonix-supervision-toolchain-bug-ledger-2026-08-05.md',
    'docs/product/confirmed-product-decisions-and-discussion-roadmap-2026-07-23.md',
    'docs/architecture/code-documentation-and-bug-architecture-audit-2026-07-23.md',
    'docs/architecture/anki-function-and-architecture-sales-comparison-2026-07-23.md',
    'docs/architecture/upstream-code-test-and-architecture-comparison-2026-07-23.md',
];
const publicGovernanceText = publicGovernanceFiles.map(read).join('\n');
for (const forbidden of [
    /D:\\Document\\/i,
    /D:\\AI-Tools\\/i,
    /C:\\Users\\Administrator\\/i,
    /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i,
    /user_id=\d{2,}/i,
]) assert.doesNotMatch(publicGovernanceText, forbidden);

console.log('Current master plan integrity contract passed.');
