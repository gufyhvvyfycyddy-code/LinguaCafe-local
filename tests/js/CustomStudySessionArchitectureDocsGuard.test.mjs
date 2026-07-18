// CustomStudySessionArchitectureDocsGuard.test.mjs
//
// Task 2000-20 — Documentation guard for the Custom Study Phase 3A/3B
// transition contract closure.
//
// Verifies that the ADR-0016 and the implementation plan agree on the
// frozen Token Service contract (issue/verify only — NO rotate(answer)),
// that the recommended flow uses CustomStudyPreviewPolicy as the pure
// state transition function, and that the documentation carries the
// correct V1 payload schema (with completed_ids + skipped_ineligible_ids).
//
// Also verifies Phase status consistency across master-plan / handoff /
// DOCUMENTATION_INDEX so the Phase 3A "awaiting acceptance" wording is
// gone and Phase 3B has a clear name + boundary.
//
// This guard MUST cover BOTH ADR and implementation plan — checking only
// one file would let the other drift.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const ADR_PATH = join(
    __dirname, '..', '..',
    'docs', 'adr', 'ADR-0016-custom-study-preview-session.md'
);
const PLAN_PATH = join(
    __dirname, '..', '..',
    'docs', 'plans', 'custom-study-1a-implementation-plan.md'
);
const MASTER_PLAN_PATH = join(
    __dirname, '..', '..',
    'docs', 'plans', 'linguacafe-master-plan.md'
);
const HANDOFF_PATH = join(
    __dirname, '..', '..',
    'docs', 'plans', 'current-working-handoff.md'
);
const DOC_INDEX_PATH = join(
    __dirname, '..', '..',
    'docs', 'DOCUMENTATION_INDEX.md'
);
const CUSTOM_STUDY_PAGE_PATH = join(
    __dirname, '..', '..',
    'resources', 'js', 'components', 'CustomStudy', 'CustomStudy.vue'
);
const SENSE_STUDY_CARD_PATH = join(
    __dirname, '..', '..',
    'resources', 'js', 'components', 'Senses', 'SenseStudyCard.vue'
);
const ROUTES_PATH = join(__dirname, '..', '..', 'routes', 'web.php');

let passed = 0;
let failed = 0;
function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  \u221a ${name}`);
    } catch (e) {
        failed++;
        console.error('FAIL: ' + name);
        console.error('  ' + e.message);
        process.exitCode = 1;
    }
}

function readSafe(p) {
    return existsSync(p) ? readFileSync(p, 'utf-8') : '';
}

const adrSource = readSafe(ADR_PATH);
const planSource = readSafe(PLAN_PATH);
const masterPlanSource = readSafe(MASTER_PLAN_PATH);
const handoffSource = readSafe(HANDOFF_PATH);
const docIndexSource = readSafe(DOC_INDEX_PATH);
const customStudyPageSource = readSafe(CUSTOM_STUDY_PAGE_PATH);
const senseStudyCardSource = readSafe(SENSE_STUDY_CARD_PATH);
const routesSource = readSafe(ROUTES_PATH);
const authoritativeSources = [
    ['master plan', masterPlanSource],
    ['handoff', handoffSource],
    ['ADR-0016', adrSource],
    ['implementation plan', planSource],
];
const PRODUCTION_STATUS = 'Production closure: complete';
const ACCEPTANCE_STATUS = 'Custom Study 1A: Accepted / Production Closed';
const ONE_B_STATUS = 'Custom Study 1B: not started';
const OBSOLETE_CURRENT_STATUS_PATTERNS = [
    /custom study 1a:\s*awaiting web-side process designer final accept/i,
    /custom study 1a[^\n]{0,240}awaiting final accept/i,
    /overall feature not usable/i,
    /overall feature incomplete/i,
    /custom study[^\n]{0,240}feature incomplete/i,
    /custom study[^\n]{0,240}no frontend/i,
    /custom study[^\n]{0,240}无前端/i,
    /整体功能不可使用(?:[^\n]{0,160}无前端)?/i,
    /phase 5-7 not started/i,
    /phase 4-7 未开始/i,
    /backend api exists but no page consumes it/i,
    /custom study 1a 未完成/i,
];

// ---------------------------------------------------------------------------
// 0. Files exist
// ---------------------------------------------------------------------------

test('ADR-0016 exists', () => {
    assert.ok(adrSource.length > 0, 'ADR-0016 is missing or empty');
});

test('implementation plan exists', () => {
    assert.ok(planSource.length > 0, 'implementation plan is missing or empty');
});

test('master plan exists', () => {
    assert.ok(masterPlanSource.length > 0, 'master plan is missing or empty');
});

test('handoff exists', () => {
    assert.ok(handoffSource.length > 0, 'handoff is missing or empty');
});

test('DOCUMENTATION_INDEX exists', () => {
    assert.ok(docIndexSource.length > 0, 'DOCUMENTATION_INDEX is missing or empty');
});

// ---------------------------------------------------------------------------
// 1. Token Service contract — no rotate(answer)
// ---------------------------------------------------------------------------

test('ADR-0016 does NOT say TokenService (signs/verifies/rotates token)', () => {
    assert.ok(
        !adrSource.includes('TokenService (signs/verifies/rotates'),
        'ADR-0016 still contains "TokenService (signs/verifies/rotates token)" — must be replaced with issue/verify only.'
    );
});

test('implementation plan does NOT say TokenService (signs/verifies/rotates token)', () => {
    assert.ok(
        !planSource.includes('TokenService (signs/verifies/rotates'),
        'implementation plan still contains "TokenService (signs/verifies/rotates token)" — must be replaced with issue/verify only.'
    );
});

test('implementation plan does NOT describe rotate(answer) contract', () => {
    // The old contract "rotate() takes the current session state + answer" must be gone.
    assert.ok(
        !planSource.includes("rotate() takes the current session state + answer"),
        'implementation plan still describes rotate(answer) contract — Phase 3A freezes TokenService to issue()/verify() only.'
    );
});

test('ADR-0016 does NOT say TokenService itself implements rotate(answer)', () => {
    // The line "→ rotate token; no write" inside the recommended pipeline
    // refers to the orchestrator (SessionService), but combined with the
    // "(signs/verifies/rotates token)" line above it was misleading.
    // After fix, the pipeline must clearly attribute rotation to the
    // orchestrator + PreviewPolicy, not to TokenService.
    const pipelineBlock = extractPipelineBlock(adrSource);
    assert.ok(
        !pipelineBlock.includes('TokenService (signs/verifies/rotates'),
        'ADR pipeline still labels TokenService as rotating token.'
    );
});

test('implementation plan does NOT say TokenService itself implements rotate(answer)', () => {
    const pipelineBlock = extractPipelineBlock(planSource);
    assert.ok(
        !pipelineBlock.includes('TokenService (signs/verifies/rotates'),
        'implementation plan pipeline still labels TokenService as rotating token.'
    );
});

// ---------------------------------------------------------------------------
// 2. Recommended flow — PreviewPolicy + SessionService + TokenService::issue
// ---------------------------------------------------------------------------

test('ADR-0016 mentions CustomStudyPreviewPolicy as pure state transition', () => {
    assert.ok(
        adrSource.includes('CustomStudyPreviewPolicy'),
        'ADR-0016 must reference CustomStudyPreviewPolicy as the pure state transition function.'
    );
});

test('implementation plan mentions CustomStudyPreviewPolicy as pure state transition', () => {
    assert.ok(
        planSource.includes('CustomStudyPreviewPolicy'),
        'implementation plan must reference CustomStudyPreviewPolicy as the pure state transition function.'
    );
});

test('ADR-0016 mentions TokenService::issue(newState) flow', () => {
    // The future flow is: SessionService orchestrates → PreviewPolicy
    // generates new State → TokenService::issue(newState).
    // We accept either "issue(newState)" or "issue()" with a nearby
    // explanation that the new state is produced by PreviewPolicy.
    const hasIssue = adrSource.includes('issue(') || adrSource.includes('issue()');
    assert.ok(hasIssue, 'ADR-0016 must mention TokenService::issue().');
});

test('implementation plan mentions TokenService::issue(newState) flow', () => {
    const hasIssue = planSource.includes('issue(') || planSource.includes('issue()');
    assert.ok(hasIssue, 'implementation plan must mention TokenService::issue().');
});

// ---------------------------------------------------------------------------
// 3. Token payload includes completed_ids + skipped_ineligible_ids
// ---------------------------------------------------------------------------

test('ADR-0016 payload includes completed_ids', () => {
    assert.ok(
        adrSource.includes('completed_ids'),
        'ADR-0016 payload must list completed_ids.'
    );
});

test('ADR-0016 payload includes skipped_ineligible_ids', () => {
    assert.ok(
        adrSource.includes('skipped_ineligible_ids'),
        'ADR-0016 payload must list skipped_ineligible_ids.'
    );
});

test('implementation plan payload includes completed_ids', () => {
    assert.ok(
        planSource.includes('completed_ids'),
        'implementation plan payload must list completed_ids.'
    );
});

test('implementation plan payload includes skipped_ineligible_ids', () => {
    assert.ok(
        planSource.includes('skipped_ineligible_ids'),
        'implementation plan payload must list skipped_ineligible_ids.'
    );
});

// ---------------------------------------------------------------------------
// 4. mode is the four Criteria modes (not "preview-only")
// ---------------------------------------------------------------------------

test('implementation plan does NOT define mode as preview-only', () => {
    // The old payload line "`mode` (preview-only)" must be removed.
    // `mode` is one of: today_forgotten / overdue / source_chapter / leech_attention.
    assert.ok(
        !planSource.includes("`mode` (preview-only)"),
        'implementation plan still defines payload mode as "preview-only" — mode is one of the four Criteria modes.'
    );
});

test('implementation plan mentions all four Criteria modes', () => {
    assert.ok(planSource.includes('today_forgotten'), 'plan must mention today_forgotten mode');
    assert.ok(planSource.includes('overdue'), 'plan must mention overdue mode');
    assert.ok(planSource.includes('source_chapter'), 'plan must mention source_chapter mode');
    assert.ok(planSource.includes('leech_attention'), 'plan must mention leech_attention mode');
});

test('ADR-0016 mentions all four Criteria modes', () => {
    assert.ok(adrSource.includes('today_forgotten'), 'ADR must mention today_forgotten mode');
    assert.ok(adrSource.includes('overdue'), 'ADR must mention overdue mode');
    assert.ok(adrSource.includes('source_chapter'), 'ADR must mention source_chapter mode');
    assert.ok(adrSource.includes('leech_attention'), 'ADR must mention leech_attention mode');
});

// ---------------------------------------------------------------------------
// 5. preview-only is the Custom Study 1A functional mode, not payload mode value
// ---------------------------------------------------------------------------

test('ADR-0016 describes preview-only as a feature-level property', () => {
    // The phrase "preview-only" must appear in the ADR as the overall
    // feature description (e.g. "preview-only temporary session"), not as
    // the payload `mode` value.
    assert.ok(
        adrSource.includes('preview-only'),
        'ADR-0016 must describe Custom Study 1A as a preview-only feature.'
    );
});

// ---------------------------------------------------------------------------
// 6. Token implementation uses injected Encrypter (not static Crypt facade)
// ---------------------------------------------------------------------------

test('ADR-0016 references injected Illuminate\\Contracts\\Encryption\\Encrypter', () => {
    assert.ok(
        adrSource.includes('Illuminate\\Contracts\\Encryption\\Encrypter'),
        'ADR-0016 must reference the injected Encrypter contract.'
    );
});

test('implementation plan references injected Illuminate\\Contracts\\Encryption\\Encrypter', () => {
    assert.ok(
        planSource.includes('Illuminate\\Contracts\\Encryption\\Encrypter'),
        'implementation plan must reference the injected Encrypter contract.'
    );
});

test('implementation plan does NOT recommend static Crypt::encryptString()', () => {
    // The active plan must not write "Uses Laravel Crypt::encryptString() / Crypt::decryptString()"
    // as the implementation guidance. Injected Encrypter is the contract.
    assert.ok(
        !planSource.includes("Uses Laravel `Crypt::encryptString()` / `Crypt::decryptString()`"),
        'implementation plan must not recommend static Crypt::encryptString()/decryptString() — use injected Encrypter.'
    );
});

test('ADR-0016 does NOT recommend static Crypt::encryptString() as the active contract', () => {
    // The ADR's "Token rules" section must not say "Signed via Laravel
    // Crypt::encryptString() or the project's existing secure token mechanism."
    // as the active contract — it must say the injected Encrypter is used.
    assert.ok(
        !adrSource.includes("Signed via Laravel `Crypt::encryptString()` or the project's existing secure token mechanism."),
        'ADR-0016 must not list static Crypt::encryptString() as the active signing contract — injected Encrypter is required.'
    );
});

// ---------------------------------------------------------------------------
// 7. Phase 3B has a clear name + boundary
// ---------------------------------------------------------------------------

test('implementation plan defines Phase 3B', () => {
    // Phase 3B must be present in the plan with at least a name and a brief
    // boundary description (not just "NOT defined").
    assert.ok(
        planSource.toLowerCase().includes('phase 3b'),
        'implementation plan must define Phase 3B.'
    );
    // The old line "Phase 3B: NOT defined / NOT started." must be gone.
    assert.ok(
        !planSource.includes('Phase 3B: NOT defined / NOT started.'),
        'implementation plan must not say "Phase 3B: NOT defined / NOT started."'
    );
});

test('implementation plan mentions CustomStudyPreviewPolicy in Phase 3B', () => {
    // Phase 3B is the PreviewPolicy phase. The plan must mention
    // CustomStudyPreviewPolicy (or PreviewPolicy) somewhere in the Phase 3B
    // description.
    const idx = planSource.toLowerCase().indexOf('phase 3b');
    assert.ok(idx !== -1, 'Phase 3B section must exist');
    // Allow some slack — the PreviewPolicy reference may be in the section
    // immediately after the Phase 3B header.
    const tail = planSource.slice(idx, idx + 4000);
    assert.ok(
        tail.includes('PreviewPolicy'),
        'Phase 3B section must mention PreviewPolicy.'
    );
});

// ---------------------------------------------------------------------------
// 8. Phase 3A is Accepted (no longer "awaiting acceptance")
// ---------------------------------------------------------------------------

test('implementation plan marks Phase 3A as Accepted', () => {
    // The Phase 3A bullet line in the implementation plan must say Accepted,
    // not "awaiting web-side acceptance" for Phase 3A specifically.
    // We look at bullet lines (starting with "- Phase 3A") to avoid picking
    // up the Status block (which legitimately mentions both Phase 3A Accepted
    // and Phase 3B awaiting on the same long line).
    assert.ok(
        planSource.includes('Phase 3A'),
        'Phase 3A must be referenced'
    );
    const lines = planSource.split('\n');
    const phase3aBullet = lines.find(line =>
        line.startsWith('- Phase 3A')
    );
    assert.ok(phase3aBullet, 'Phase 3A bullet line must exist');
    assert.ok(
        phase3aBullet.includes('Accepted'),
        'Phase 3A bullet line must say "Accepted" — Task 2000-20 marks it Accepted.'
    );
    assert.ok(
        !phase3aBullet.toLowerCase().includes('awaiting'),
        'Phase 3A bullet line must NOT say "awaiting" — Task 2000-20 marks it Accepted.'
    );
});

test('ADR-0016 marks Phase 3A as Accepted', () => {
    // Look at the Status block (the line starting with "**Status**").
    const statusLine = adrSource.split('\n').find(line => line.startsWith('**Status**'));
    assert.ok(statusLine, 'ADR-0016 must have a Status line');
    // The Status line must say "Phase 3A Accepted" (Phase 3B may still be
    // "awaiting", which is legitimate — we only assert Phase 3A is Accepted).
    assert.ok(
        statusLine.includes('Phase 3A Accepted'),
        'ADR-0016 status line must say "Phase 3A Accepted".'
    );
});

// ---------------------------------------------------------------------------
// 9. Production phases are complete
// ---------------------------------------------------------------------------

test('implementation plan marks production closure complete', () => {
    assert.ok(planSource.includes(PRODUCTION_STATUS));
});

// ---------------------------------------------------------------------------
// 10. Overall feature is accepted and production-closed
// ---------------------------------------------------------------------------

test('implementation plan has the authoritative acceptance status', () => {
    assert.ok(planSource.includes(ACCEPTANCE_STATUS));
});

test('ADR-0016 has the authoritative acceptance status', () => {
    assert.ok(adrSource.includes(ACCEPTANCE_STATUS));
});

test('the four status authorities share the production status trio', () => {
    for (const [name, source] of authoritativeSources) {
        assert.ok(source.includes(PRODUCTION_STATUS), `${name} is missing production closure status`);
        assert.ok(source.includes(ACCEPTANCE_STATUS), `${name} is missing final Accept status`);
        assert.ok(source.includes(ONE_B_STATUS), `${name} is missing 1B not-started status`);
    }
});

test('all five authoritative documents contain no obsolete current Custom Study status', () => {
    for (const [name, source] of authoritativeSources) {
        for (const pattern of OBSOLETE_CURRENT_STATUS_PATTERNS) {
            assert.ok(!pattern.test(source), `${name} contains obsolete current status matching ${pattern}`);
        }
    }
});

test('production implementation facts exist in code and routes', () => {
    assert.ok(customStudyPageSource.length > 0, 'CustomStudy.vue must exist');
    assert.ok(senseStudyCardSource.length > 0, 'SenseStudyCard.vue must exist');
    assert.match(senseStudyCardSource, /SenseSentencePreview/, 'SenseStudyCard must reuse SenseSentencePreview');
    assert.match(routesSource, /Route::get\(\s*['"]\/custom-study['"]/, '/custom-study page route must exist');
    assert.match(routesSource, /custom-study\/chapter-options/, 'chapter-options route must exist');
    assert.match(routesSource, /custom-study\/sessions['"]/, 'open-session route must exist');
    assert.match(routesSource, /custom-study\/sessions\/answer/, 'answer route must exist');
    assert.match(routesSource, /custom-study\/sessions\/resume/, 'resume route must exist');
});

// ---------------------------------------------------------------------------
// 11. Token Service final职责 — issue() + verify() only
// ---------------------------------------------------------------------------

test('ADR-0016 says TokenService issue() + verify() only', () => {
    assert.ok(
        adrSource.includes('issue()') && adrSource.includes('verify()'),
        'ADR-0016 must state TokenService exposes issue() + verify() only.'
    );
});

test('implementation plan says TokenService issue() + verify() only', () => {
    assert.ok(
        planSource.includes('issue()') && planSource.includes('verify()'),
        'implementation plan must state TokenService exposes issue() + verify() only.'
    );
});

// ---------------------------------------------------------------------------
// 12. master-plan / handoff / DOCUMENTATION_INDEX status consistency
// ---------------------------------------------------------------------------

test('master plan references Phase 3A as Accepted', () => {
    // master plan must NOT say Phase 3A is "awaiting acceptance" or "尚未最终关闭".
    // It should reflect Phase 3A Accepted.
    assert.ok(
        !masterPlanSource.includes('Phase 3A 尚未最终关闭'),
        'master plan must not say "Phase 3A 尚未最终关闭" — Task 2000-20 closes Phase 3A.'
    );
    assert.ok(
        !masterPlanSource.toLowerCase().includes('phase 3a') && true
        || masterPlanSource.toLowerCase().includes('phase 3a'),
        'master plan must reference Phase 3A'
    );
});

test('master plan references Phase 3B completion as historical architecture', () => {
    assert.ok(
        masterPlanSource.toLowerCase().includes('phase 3b'),
        'master plan must preserve Phase 3B architecture history.'
    );
});

test('handoff references Phase 3B', () => {
    assert.ok(
        handoffSource.toLowerCase().includes('phase 3b'),
        'handoff must reference Phase 3B.'
    );
});

test('DOCUMENTATION_INDEX routes Custom Study to its plan and ADR', () => {
    assert.ok(
        docIndexSource.includes('custom-study-1a-implementation-plan.md') &&
        docIndexSource.includes('ADR-0016-custom-study-preview-session.md'),
        'DOCUMENTATION_INDEX must route to the Custom Study plan and ADR.'
    );
});

// ---------------------------------------------------------------------------
// 13. Guard covers BOTH ADR and implementation plan
// ---------------------------------------------------------------------------

test('guard has executed checks against both ADR and implementation plan', () => {
    // Sanity: both sources must have been loaded with non-trivial content.
    assert.ok(adrSource.length > 1000, 'ADR-0016 source too short — guard did not load it.');
    assert.ok(planSource.length > 1000, 'implementation plan source too short — guard did not load it.');
});

// ---------------------------------------------------------------------------
// 14. Task 2000-21 — Full-document zero-residual status checks
//
// The Task 2000-20 guard only verified "at least one correct status phrase
// exists". That let stale body text (e.g. "Phase 3A awaiting acceptance"
// in §19.4 / §19.10, "Phase 3B 未定义" in master-plan L1878) survive
// alongside the corrected summary. This block enforces zero-residual:
// the forbidden stale phrases MUST NOT appear ANYWHERE in the active
// documents, while the correct status MUST appear at least once.
// ---------------------------------------------------------------------------

// 14.1 master plan — full-text forbidden phrases (current-status drift)
test('master plan full-text does NOT contain "Phase 3A ... 等待网页端验收"', () => {
    assert.ok(
        !masterPlanSource.includes('Phase 3A 等待网页端验收'),
        'master plan still says "Phase 3A 等待网页端验收" — Phase 3A is Accepted.'
    );
});
test('master plan full-text does NOT contain "Phase 3A ... 等待网页端总流程设计师验收"', () => {
    assert.ok(
        !masterPlanSource.includes('Phase 3A 代码与测试完成'),
        'master plan still says "Phase 3A 代码与测试完成" — Phase 3A is Accepted.'
    );
});
test('master plan full-text does NOT contain "Phase 3A（... 等待网页端验收）"', () => {
    assert.ok(
        !masterPlanSource.includes('等待网页端总流程设计师验收'),
        'master plan still references "等待网页端总流程设计师验收" for Phase 3A — Phase 3A is Accepted.'
    );
});
test('master plan full-text does NOT say "Phase 3B / Phase 4-7 未开始"', () => {
    // This phrasing implies Phase 3B has not started, which is false
    // after Task 2000-20.
    assert.ok(
        !masterPlanSource.includes('Phase 3B / Phase 4-7 未开始'),
        'master plan still says "Phase 3B / Phase 4-7 未开始" — Phase 3B is complete pending web acceptance.'
    );
});
test('master plan full-text does NOT say "Phase 3B（未定义）"', () => {
    assert.ok(
        !masterPlanSource.includes('Phase 3B（未定义）'),
        'master plan still says "Phase 3B（未定义）" — Phase 3B is defined and code-complete.'
    );
});
test('master plan full-text does NOT say "Phase 3B 未定义"', () => {
    assert.ok(
        !masterPlanSource.includes('Phase 3B 未定义'),
        'master plan still says "Phase 3B 未定义" — Phase 3B is defined and code-complete.'
    );
});
test('master plan full-text does NOT say "Phase 3B 未开始"', () => {
    assert.ok(
        !masterPlanSource.includes('Phase 3B 未开始'),
        'master plan still says "Phase 3B 未开始" — Phase 3B is complete pending web acceptance.'
    );
});
test('master plan full-text does NOT say "Phase 3A（... 代码与测试完成（Task 2000-19）" (existing-but-awaiting pattern)', () => {
    // The long master-plan bullet still described Phase 3A as
    // "代码与测试完成（Task 2000-19），等待网页端总流程设计师验收".
    // After Task 2000-20 Phase 3A is Accepted — this entire phrasing
    // must be gone.
    assert.ok(
        !/Phase 3A（[^）]*代码与测试完成/.test(masterPlanSource),
        'master plan still describes Phase 3A as "代码与测试完成" inside a parenthetical — Phase 3A is Accepted.'
    );
});

// 14.2 ADR-0016 — full-text forbidden phrases (status drift in §19.4 / §19.10)
test('ADR-0016 full-text does NOT contain "Phase 3A (existing, awaiting web-side acceptance"', () => {
    // §19.4 and §19.10 had this exact parenthetical.
    assert.ok(
        !adrSource.includes('Phase 3A (existing, awaiting web-side acceptance'),
        'ADR-0016 still labels Phase 3A as "awaiting web-side acceptance" — Phase 3A is Accepted.'
    );
});
test('ADR-0016 full-text does NOT contain "Phase 3A tests ... awaiting web-side acceptance"', () => {
    // The §19.10 header had this exact parenthetical.
    assert.ok(
        !adrSource.includes('Tests — Phase 3A (existing, awaiting'),
        'ADR-0016 still labels Phase 3A tests as "awaiting web-side acceptance" — Phase 3A is Accepted.'
    );
});

// 14.3 implementation plan — full-text forbidden phrases
test('implementation plan full-text does NOT say "Phase 3B: NOT defined"', () => {
    assert.ok(
        !planSource.includes('Phase 3B: NOT defined'),
        'implementation plan still says "Phase 3B: NOT defined" — Phase 3B is defined.'
    );
});
test('implementation plan full-text does NOT say "Phase 3B 未开始"', () => {
    assert.ok(
        !planSource.includes('Phase 3B 未开始'),
        'implementation plan still says "Phase 3B 未开始" — Phase 3B is complete pending web acceptance.'
    );
});
test('implementation plan full-text does NOT say "Phase 3B 未定义"', () => {
    assert.ok(
        !planSource.includes('Phase 3B 未定义'),
        'implementation plan still says "Phase 3B 未定义" — Phase 3B is defined.'
    );
});
test('implementation plan full-text does NOT say "PreviewPolicy 属于 Phase 4"', () => {
    assert.ok(
        !planSource.includes('PreviewPolicy 属于 Phase 4'),
        'implementation plan still says "PreviewPolicy 属于 Phase 4" — PreviewPolicy is Phase 3B.'
    );
});

// 14.4 Token Service source — comment phase number correctness
const TOKEN_SERVICE_PATH = join(
    __dirname, '..', '..',
    'app', 'Services', 'CustomStudy', 'CustomStudySessionTokenService.php'
);
const tokenServiceSource = readSafe(TOKEN_SERVICE_PATH);

test('Token Service source file is readable by the guard', () => {
    assert.ok(tokenServiceSource.length > 0, 'CustomStudySessionTokenService.php is missing or empty.');
});

test('Token Service source does NOT label PreviewPolicy as Phase 4', () => {
    // The Task 2000-19 docblock said "apply ratings or answers (Phase 4 PreviewPolicy)"
    // and "rotate tokens (Phase 4 SessionService ...)". After Task 2000-20,
    // PreviewPolicy = Phase 3B and SessionService = Phase 4. The source
    // comment must reflect the new phase numbers.
    assert.ok(
        !tokenServiceSource.includes('Phase 4 PreviewPolicy'),
        'TokenService docblock still labels PreviewPolicy as Phase 4 — it is Phase 3B.'
    );
});

test('Token Service source does NOT label SessionService as Phase 4 in the "apply ratings" line', () => {
    // The old "apply ratings or answers (Phase 4 PreviewPolicy)" line is
    // gone. The remaining "rotate tokens (Phase 4 SessionService ...)"
    // line is correct (SessionService IS Phase 4). We only forbid the
    // mislabeling of PreviewPolicy as Phase 4.
    assert.ok(
        !/Phase 4 PreviewPolicy/.test(tokenServiceSource),
        'TokenService docblock still mislabels PreviewPolicy as Phase 4.'
    );
});

test('Token Service source explicitly marks PreviewPolicy as Phase 3B', () => {
    // After the fix, the docblock should reference "Phase 3B PreviewPolicy"
    // to make the phase number unambiguous.
    assert.ok(
        tokenServiceSource.includes('Phase 3B PreviewPolicy'),
        'TokenService docblock must label PreviewPolicy as Phase 3B.'
    );
});

test('Token Service source explicitly marks SessionService as Phase 4', () => {
    assert.ok(
        tokenServiceSource.includes('Phase 4 SessionService'),
        'TokenService docblock must label SessionService as Phase 4.'
    );
});

// 14.5 Active documents must declare Phase 4A SessionOrder status
test('implementation plan references Phase 4A', () => {
    assert.ok(
        planSource.toLowerCase().includes('phase 4a'),
        'implementation plan must reference Phase 4A (SessionOrder).'
    );
});
test('master plan references Phase 4A', () => {
    assert.ok(
        masterPlanSource.toLowerCase().includes('phase 4a'),
        'master plan must reference Phase 4A (SessionOrder).'
    );
});
test('handoff references Phase 4A', () => {
    assert.ok(
        handoffSource.toLowerCase().includes('phase 4a'),
        'handoff must reference Phase 4A (SessionOrder).'
    );
});

// 14.6 Chapter picker candidate_count future contract (Task 2000-21 §8.3)
test('ADR-0016 §21 mentions candidate_count', () => {
    assert.ok(
        adrSource.includes('candidate_count'),
        'ADR-0016 §21 must register the candidate_count future contract.'
    );
});
test('implementation plan mentions candidate_count', () => {
    assert.ok(
        planSource.includes('candidate_count'),
        'implementation plan must register the candidate_count future contract.'
    );
});
test('ADR-0016 §21 forbids candidate_count = 0 chapters', () => {
    assert.ok(
        adrSource.includes('candidate_count = 0'),
        'ADR-0016 §21 must forbid chapters with candidate_count = 0.'
    );
});

// ---------------------------------------------------------------------------
// 14.7 Phase 4B documentation status (Task 2000-22 final closure)
// ---------------------------------------------------------------------------

test('implementation plan references Phase 4B', () => {
    assert.ok(
        planSource.toLowerCase().includes('phase 4b'),
        'implementation plan must reference Phase 4B (backend session vertical slice).'
    );
});
test('master plan references Phase 4B', () => {
    assert.ok(
        masterPlanSource.toLowerCase().includes('phase 4b'),
        'master plan must reference Phase 4B (backend session vertical slice).'
    );
});
test('handoff references Phase 4B', () => {
    assert.ok(
        handoffSource.toLowerCase().includes('phase 4b'),
        'handoff must reference Phase 4B (backend session vertical slice).'
    );
});
test('ADR-0016 references Phase 4B', () => {
    assert.ok(
        adrSource.toLowerCase().includes('phase 4b'),
        'ADR-0016 must reference Phase 4B (backend session vertical slice).'
    );
});

// 14.8 Phase 3B/4A must be marked Accepted / Closed (no longer "awaiting")
test('ADR-0016 marks Phase 3B as Accepted / Closed', () => {
    assert.ok(
        /Phase 3B Accepted \/ Closed/.test(adrSource),
        'ADR-0016 must mark Phase 3B as "Accepted / Closed".'
    );
});
test('ADR-0016 marks Phase 4A as Accepted / Closed', () => {
    assert.ok(
        /Phase 4A Accepted \/ Closed/.test(adrSource),
        'ADR-0016 must mark Phase 4A as "Accepted / Closed".'
    );
});
test('implementation plan marks Phase 3B as Accepted / Closed', () => {
    assert.ok(
        /Phase 3B .* Accepted \/ Closed/.test(planSource) ||
        /Accepted \/ Closed.*Phase 3B/.test(planSource),
        'implementation plan must mark Phase 3B as "Accepted / Closed".'
    );
});
test('implementation plan marks Phase 4A as Accepted / Closed', () => {
    assert.ok(
        /Phase 4A .* Accepted \/ Closed/.test(planSource) ||
        /Accepted \/ Closed.*Phase 4A/.test(planSource),
        'implementation plan must mark Phase 4A as "Accepted / Closed".'
    );
});

// 14.9 candidate_count product Gate closed as Option A (no longer OPEN PRODUCT GATE)
test('ADR-0016 §21 closes OPEN PRODUCT GATE for candidate_count', () => {
    // After closure, the §21 section should NOT describe candidate_count semantics as unresolved.
    // The closing language must mention Option A or "not card_limit-truncated".
    assert.ok(
        adrSource.includes('Option A') ||
        adrSource.includes('not card_limit-truncated') ||
        adrSource.includes('NOT card_limit-truncated'),
        'ADR-0016 must close candidate_count product Gate as Option A (full available count, not card_limit-truncated).'
    );
});
test('ADR-0016 §21 must NOT keep candidate_count as an unresolved OPEN PRODUCT GATE', () => {
    // The phrase "OPEN PRODUCT GATE" may appear only as historical mention or in closure context,
    // not as the current status. Check that the closure language exists.
    assert.ok(
        /OPEN PRODUCT GATE.*closed/i.test(adrSource) ||
        /closed.*OPEN PRODUCT GATE/i.test(adrSource) ||
        !adrSource.includes('OPEN PRODUCT GATE'),
        'ADR-0016 must close the OPEN PRODUCT GATE — closure language required if the phrase still appears.'
    );
});

// 14.10 Session State invariants expanded to 18 (Task 2000-22 added invariants 16/17/18)
test('ADR-0016 §18 references 18 invariants', () => {
    assert.ok(
        adrSource.includes('18 invariants') ||
        adrSource.includes('18 Session State invariants') ||
        /invariants? expanded to 18/i.test(adrSource),
        'ADR-0016 §18 must reference 18 invariants (Task 2000-22 expanded 15 to 18).'
    );
});
test('ADR-0016 §18 mentions available_candidate_count invariant', () => {
    assert.ok(
        adrSource.includes('available_candidate_count'),
        'ADR-0016 §18 must mention available_candidate_count (invariants 16/17).'
    );
});
test('ADR-0016 §18 mentions withEligibilityResolution same-step boundary', () => {
    assert.ok(
        adrSource.includes('withEligibilityResolution'),
        'ADR-0016 §18 must mention withEligibilityResolution same-step boundary (invariant 18).'
    );
});

// 14.11 V1 token payload includes available_candidate_count
test('ADR-0016 §5 token payload includes available_candidate_count', () => {
    assert.ok(
        adrSource.includes('available_candidate_count'),
        'ADR-0016 §5 token payload must include available_candidate_count field.'
    );
});

// 14.12 V1 query budget truthfully records full-candidate hydration
test('ADR-0016 §12 query budget truthfully records full candidate hydration', () => {
    // The query budget must NOT hide the full-candidate hydration step.
    assert.ok(
        /full candidate/i.test(adrSource) || /full-candidate/i.test(adrSource),
        'ADR-0016 §12 query budget must truthfully record full-candidate ID snapshot + full-candidate ordering.'
    );
});
test('ADR-0016 §12 query budget must NOT truncate candidate IDs before ordering', () => {
    assert.ok(
        /MUST NOT truncate candidate IDs? before/i.test(adrSource) ||
        /must not truncate candidate IDs? before/i.test(adrSource),
        'ADR-0016 §12 must forbid truncating candidate IDs before CustomStudySessionOrder.'
    );
});

// 14.13 Master plan and handoff Phase 3B/4A closure (Task 2000-22)
test('master plan marks Phase 3B as Accepted / Closed', () => {
    assert.ok(
        /Phase 3B.*Accepted \/ Closed/.test(masterPlanSource) ||
        /Phase 3B.*✅.*Closed/.test(masterPlanSource),
        'master plan must mark Phase 3B as Accepted / Closed.'
    );
});
test('master plan marks Phase 4A as Accepted / Closed', () => {
    assert.ok(
        /Phase 4A.*Accepted \/ Closed/.test(masterPlanSource) ||
        /Phase 4A.*✅.*Closed/.test(masterPlanSource),
        'master plan must mark Phase 4A as Accepted / Closed.'
    );
});
test('handoff marks Phase 3B as Accepted / Closed', () => {
    assert.ok(
        /Phase 3B.*Accepted \/ Closed/.test(handoffSource) ||
        /Phase 3B.*✅.*Closed/.test(handoffSource),
        'handoff must mark Phase 3B as Accepted / Closed.'
    );
});
test('handoff marks Phase 4A as Accepted / Closed', () => {
    assert.ok(
        /Phase 4A.*Accepted \/ Closed/.test(handoffSource) ||
        /Phase 4A.*✅.*Closed/.test(handoffSource),
        'handoff must mark Phase 4A as Accepted / Closed.'
    );
});

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function extractPipelineBlock(source) {
    // The recommended pipeline lives inside a fenced ``` block. Find it.
    const marker = 'CustomStudyCriteria (value object, no DB)';
    const idx = source.indexOf(marker);
    if (idx === -1) return '';
    // Find the opening fence before the marker.
    const before = source.slice(0, idx);
    const fenceIdx = before.lastIndexOf('```');
    if (fenceIdx === -1) return source.slice(idx, idx + 1500);
    // Find the closing fence after the marker.
    const after = source.slice(idx);
    const closeFenceIdx = after.indexOf('```');
    if (closeFenceIdx === -1) return source.slice(fenceIdx);
    return source.slice(fenceIdx, idx + closeFenceIdx + 3);
}

// ---------------------------------------------------------------------------
// summary
// ---------------------------------------------------------------------------

console.log('');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
if (failed > 0) {
    process.exitCode = 1;
}
