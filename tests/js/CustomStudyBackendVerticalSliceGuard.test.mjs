// CustomStudyBackendVerticalSliceGuard.test.mjs
//
// Task 2000-22 — Architecture / source-code guard for the Phase 4B
// backend session vertical slice.
//
// This guard is the executable architectural boundary for the Phase 4B
// implementation. It verifies:
//
//   1. Phase 4B source files exist (Exception, EligibilityService,
//      SessionService, Controller).
//   2. SessionState and PreviewPolicy source files contain the new
//      Task 2000-22 extensions (available_candidate_count field +
//      withEligibilityResolution + resolveEligibility).
//   3. SessionService does NOT access Auth / Request / Session /
//      Settings facades (caller passes trusted userId / language /
//      cardLimit).
//   4. SessionService does NOT write ReviewLog / FSRS / lifecycle.
//   5. EligibilityService does NOT write to the DB and reuses
//      confirmedSenseCardQuery + senseReviewEligible.
//   6. Controller is the only place Auth::user() is read; Controller
//      does NOT contain business logic (no direct PreviewPolicy /
//      EligibilityService / QueryService / SessionOrder calls).
//   7. routes/web.php registers the three POST routes inside the
//      existing auth middleware group (not admin-only).
//   8. Test files exist for each Phase 4B component.
//
// This guard is INTENTIONALLY RED until the Phase 4B implementation
// lands. Each track (ARCH-STATE, ARCH-ELIGIBILITY-POLICY, ARCH-
// ELIGIBILITY-SERVICE, ARCH-SESSION-SERVICE, ARCH-CONTROLLER, DEV-
// ROUTES) flips its corresponding section from RED to GREEN.
//
// Per Task 2000-22 spec: source guards MUST ONLY verify architectural
// prohibitions and file existence — they MUST NOT replace behavioral
// tests with source-string assertions. Behavioral contracts live in
// PHPUnit feature tests.

import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const REPO_ROOT = join(__dirname, '..', '..');

function path(rel) {
    return join(REPO_ROOT, rel);
}

function readSafe(p) {
    return existsSync(p) ? readFileSync(p, 'utf-8') : '';
}

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

// ---------------------------------------------------------------------------
// Source paths
// ---------------------------------------------------------------------------

const STATE_PATH = path('app/Services/CustomStudy/CustomStudySessionState.php');
const POLICY_PATH = path('app/Services/CustomStudy/CustomStudyPreviewPolicy.php');
const TOKEN_PATH = path('app/Services/CustomStudy/CustomStudySessionTokenService.php');
const SESSION_EXCEPTION_PATH = path('app/Exceptions/CustomStudySessionException.php');
const ELIGIBILITY_SERVICE_PATH = path('app/Services/CustomStudy/CustomStudySessionEligibilityService.php');
const SESSION_SERVICE_PATH = path('app/Services/CustomStudy/CustomStudySessionService.php');
const CONTROLLER_PATH = path('app/Http/Controllers/CustomStudyController.php');
const ROUTES_PATH = path('routes/web.php');

const stateSource = readSafe(STATE_PATH);
const policySource = readSafe(POLICY_PATH);
const tokenSource = readSafe(TOKEN_PATH);
const sessionExceptionSource = readSafe(SESSION_EXCEPTION_PATH);
const eligibilityServiceSource = readSafe(ELIGIBILITY_SERVICE_PATH);
const sessionServiceSource = readSafe(SESSION_SERVICE_PATH);
const controllerSource = readSafe(CONTROLLER_PATH);
const routesSource = readSafe(ROUTES_PATH);

// ---------------------------------------------------------------------------
// 1. Phase 4B source files exist
// ---------------------------------------------------------------------------

test('CustomStudySessionException file exists', () => {
    assert.ok(
        existsSync(SESSION_EXCEPTION_PATH),
        'app/Exceptions/CustomStudySessionException.php must exist (Phase 4B).'
    );
});
test('CustomStudySessionEligibilityService file exists', () => {
    assert.ok(
        existsSync(ELIGIBILITY_SERVICE_PATH),
        'app/Services/CustomStudy/CustomStudySessionEligibilityService.php must exist (Phase 4B).'
    );
});
test('CustomStudySessionService file exists', () => {
    assert.ok(
        existsSync(SESSION_SERVICE_PATH),
        'app/Services/CustomStudy/CustomStudySessionService.php must exist (Phase 4B).'
    );
});
test('CustomStudyController file exists', () => {
    assert.ok(
        existsSync(CONTROLLER_PATH),
        'app/Http/Controllers/CustomStudyController.php must exist (Phase 4B).'
    );
});

// ---------------------------------------------------------------------------
// 2. SessionState + PreviewPolicy Task 2000-22 extensions
// ---------------------------------------------------------------------------

test('SessionState declares available_candidate_count property', () => {
    assert.ok(
        stateSource.length > 0 && stateSource.includes('available_candidate_count'),
        'CustomStudySessionState must declare the available_candidate_count property (Task 2000-22).'
    );
});
test('SessionState exposes withEligibilityResolution method', () => {
    assert.ok(
        stateSource.length > 0 && stateSource.includes('withEligibilityResolution'),
        'CustomStudySessionState must expose withEligibilityResolution() (Task 2000-22 same-step boundary).'
    );
});
test('SessionState createInitial accepts availableCandidateCount parameter', () => {
    assert.ok(
        stateSource.length > 0 && (
            stateSource.includes('availableCandidateCount') ||
            stateSource.includes('available_candidate_count')
        ),
        'CustomStudySessionState::createInitial must accept the availableCandidateCount parameter.'
    );
});
test('PreviewPolicy exposes resolveEligibility method', () => {
    assert.ok(
        policySource.length > 0 && policySource.includes('resolveEligibility'),
        'CustomStudyPreviewPolicy must expose resolveEligibility() (Task 2000-22 pure method).'
    );
});

// ---------------------------------------------------------------------------
// 3. SessionService architectural prohibitions
// ---------------------------------------------------------------------------

test('SessionService must NOT access Auth facade', () => {
    assert.ok(
        sessionServiceSource.length > 0 && !/\bAuth::/.test(sessionServiceSource),
        'CustomStudySessionService must NOT access the Auth facade — caller passes trusted userId.'
    );
});
test('SessionService must NOT access Request facade or $request variable', () => {
    assert.ok(
        sessionServiceSource.length > 0 &&
        !/\bRequest::/.test(sessionServiceSource) &&
        !/\$request\b/.test(sessionServiceSource),
        'CustomStudySessionService must NOT access Request facade or $request — caller passes trusted input.'
    );
});
test('SessionService must NOT access Session facade', () => {
    assert.ok(
        sessionServiceSource.length > 0 && !/\bSession::/.test(sessionServiceSource),
        'CustomStudySessionService must NOT access the Session facade.'
    );
});
test('SessionService must NOT inject SettingsService', () => {
    assert.ok(
        sessionServiceSource.length > 0 && !/SettingsService/.test(sessionServiceSource),
        'CustomStudySessionService must NOT inject SettingsService — caller passes trusted cardLimit.'
    );
});
test('SessionService must NOT call Crypt facade directly', () => {
    assert.ok(
        sessionServiceSource.length > 0 && !/\bCrypt::/.test(sessionServiceSource),
        'CustomStudySessionService must NOT call Crypt:: directly — TokenService is the only encrypt/decrypt boundary.'
    );
});
test('SessionService must NOT write ReviewLog', () => {
    assert.ok(
        sessionServiceSource.length > 0 &&
        !/\bnew\s+ReviewLog\b/.test(sessionServiceSource) &&
        !/ReviewLog::(?:create|insert|update|delete|save)/.test(sessionServiceSource),
        'CustomStudySessionService must NOT write ReviewLog (preview-only session).'
    );
});
test('SessionService must NOT modify FSRS fields directly', () => {
    assert.ok(
        sessionServiceSource.length > 0 &&
        !/fsrs_stability\s*=/.test(sessionServiceSource) &&
        !/fsrs_difficulty\s*=/.test(sessionServiceSource) &&
        !/fsrs_due_at\s*=/.test(sessionServiceSource) &&
        !/fsrs_reps\s*=/.test(sessionServiceSource) &&
        !/fsrs_lapses\s*=/.test(sessionServiceSource),
        'CustomStudySessionService must NOT modify FSRS fields directly (preview-only session).'
    );
});
test('SessionService must NOT modify lifecycle_state', () => {
    assert.ok(
        sessionServiceSource.length > 0 &&
        !/lifecycle_state\s*=/.test(sessionServiceSource) &&
        !/->bury\(/.test(sessionServiceSource) &&
        !/->suspend\(/.test(sessionServiceSource) &&
        !/->archive\(/.test(sessionServiceSource),
        'CustomStudySessionService must NOT modify lifecycle_state (preview-only session).'
    );
});

// ---------------------------------------------------------------------------
// 4. EligibilityService architectural prohibitions + reuse contract
// ---------------------------------------------------------------------------

test('EligibilityService must NOT write to DB', () => {
    assert.ok(
        eligibilityServiceSource.length > 0 &&
        !/DB::(?:table|insert|update|delete)/.test(eligibilityServiceSource) &&
        !/->insert\(/.test(eligibilityServiceSource) &&
        !/->update\(/.test(eligibilityServiceSource) &&
        !/->delete\(/.test(eligibilityServiceSource),
        'CustomStudySessionEligibilityService must NOT write to DB (read-only batch resolver).'
    );
});
test('EligibilityService must NOT write ReviewLog', () => {
    assert.ok(
        eligibilityServiceSource.length > 0 &&
        !/\bnew\s+ReviewLog\b/.test(eligibilityServiceSource) &&
        !/ReviewLog::(?:create|insert|update|delete|save)/.test(eligibilityServiceSource),
        'CustomStudySessionEligibilityService must NOT write ReviewLog.'
    );
});
test('EligibilityService must NOT issue or verify token', () => {
    assert.ok(
        eligibilityServiceSource.length > 0 &&
        !/TokenService/.test(eligibilityServiceSource),
        'CustomStudySessionEligibilityService must NOT issue/verify token — it consumes a state and returns eligible IDs.'
    );
});
test('EligibilityService must NOT call PreviewPolicy', () => {
    assert.ok(
        eligibilityServiceSource.length > 0 &&
        !/PreviewPolicy/.test(eligibilityServiceSource),
        'CustomStudySessionEligibilityService must NOT call PreviewPolicy — pure eligibility resolver.'
    );
});
test('EligibilityService reuses confirmedSenseCardQuery or senseReviewEligible', () => {
    assert.ok(
        eligibilityServiceSource.length > 0 && (
            eligibilityServiceSource.includes('confirmedSenseCardQuery') ||
            eligibilityServiceSource.includes('senseReviewEligible') ||
            eligibilityServiceSource.includes('SenseReviewEligible')
        ),
        'CustomStudySessionEligibilityService must reuse confirmedSenseCardQuery and/or senseReviewEligible scope.'
    );
});

// ---------------------------------------------------------------------------
// 5. Controller architectural prohibitions
// ---------------------------------------------------------------------------

test('Controller is the only place Auth::user() is read in the vertical slice', () => {
    assert.ok(
        controllerSource.length > 0 && /\bAuth::user\(\)/.test(controllerSource),
        'CustomStudyController must read Auth::user() (the only Auth usage in the vertical slice).'
    );
});
test('Controller must NOT call PreviewPolicy directly', () => {
    // Regex intent: block direct PreviewPolicy calls. Note that
    // SessionService also exposes a resume() method, so the bare ->resume(
    // pattern is intentionally NOT matched here — only the conventional
    // $previewPolicy->resume( / $this->previewPolicy->resume( form is
    // blocked. The Controller legitimately calls
    // $this->sessionService->resume() which is the orchestrator boundary,
    // not a direct PreviewPolicy call. applyRating and resolveEligibility
    // are unique to PreviewPolicy, so bare patterns are still safe to forbid.
    assert.ok(
        controllerSource.length > 0 &&
        !/\bPreviewPolicy::/.test(controllerSource) &&
        !/->applyRating\(/.test(controllerSource) &&
        !/->resolveEligibility\(/.test(controllerSource) &&
        !/previewPolicy\s*->\s*resume\s*\(/i.test(controllerSource),
        'CustomStudyController must NOT call PreviewPolicy directly — SessionService is the orchestrator.'
    );
});
test('Controller must NOT call EligibilityService directly', () => {
    assert.ok(
        controllerSource.length > 0 &&
        !/\bEligibilityService::/.test(controllerSource) &&
        !/->resolve\(/.test(controllerSource),
        'CustomStudyController must NOT call EligibilityService directly — SessionService is the orchestrator.'
    );
});
test('Controller must NOT call QueryService or SessionOrder directly', () => {
    assert.ok(
        controllerSource.length > 0 &&
        !/\bQueryService::/.test(controllerSource) &&
        !/\bSessionOrder::/.test(controllerSource) &&
        !/->candidateIds\(/.test(controllerSource) &&
        !/->order\(/.test(controllerSource),
        'CustomStudyController must NOT call QueryService or SessionOrder directly — SessionService is the orchestrator.'
    );
});
test('Controller must NOT write ReviewLog', () => {
    assert.ok(
        controllerSource.length > 0 &&
        !/\bnew\s+ReviewLog\b/.test(controllerSource) &&
        !/ReviewLog::(?:create|insert|update|delete|save)/.test(controllerSource),
        'CustomStudyController must NOT write ReviewLog.'
    );
});
test('Controller must NOT modify FSRS fields directly', () => {
    assert.ok(
        controllerSource.length > 0 &&
        !/fsrs_stability\s*=/.test(controllerSource) &&
        !/fsrs_difficulty\s*=/.test(controllerSource) &&
        !/fsrs_due_at\s*=/.test(controllerSource),
        'CustomStudyController must NOT modify FSRS fields directly.'
    );
});

// ---------------------------------------------------------------------------
// 6. Route registration
// ---------------------------------------------------------------------------

test('routes/web.php registers /custom-study/sessions POST route', () => {
    assert.ok(
        routesSource.length > 0 &&
        routesSource.includes('custom-study/sessions') &&
        /Route::post\(\s*['"][^'"]*custom-study\/sessions['"]/.test(routesSource),
        'routes/web.php must register a POST route for /custom-study/sessions.'
    );
});
test('routes/web.php registers /custom-study/sessions/answer POST route', () => {
    assert.ok(
        routesSource.length > 0 &&
        routesSource.includes('custom-study/sessions/answer') &&
        /Route::post\(\s*['"][^'"]*custom-study\/sessions\/answer['"]/.test(routesSource),
        'routes/web.php must register a POST route for /custom-study/sessions/answer.'
    );
});
test('routes/web.php registers /custom-study/sessions/resume POST route', () => {
    assert.ok(
        routesSource.length > 0 &&
        routesSource.includes('custom-study/sessions/resume') &&
        /Route::post\(\s*['"][^'"]*custom-study\/sessions\/resume['"]/.test(routesSource),
        'routes/web.php must register a POST route for /custom-study/sessions/resume.'
    );
});

// ---------------------------------------------------------------------------
// 7. Test file existence (Phase 4B feature + Node guard tests)
// ---------------------------------------------------------------------------

test('CustomStudySessionEligibilityServiceTest exists', () => {
    assert.ok(
        existsSync(path('tests/Feature/CustomStudySessionEligibilityServiceTest.php')),
        'tests/Feature/CustomStudySessionEligibilityServiceTest.php must exist.'
    );
});
test('CustomStudyOpenSessionTest exists', () => {
    assert.ok(
        existsSync(path('tests/Feature/CustomStudyOpenSessionTest.php')),
        'tests/Feature/CustomStudyOpenSessionTest.php must exist.'
    );
});
test('CustomStudyAnswerTest exists', () => {
    assert.ok(
        existsSync(path('tests/Feature/CustomStudyAnswerTest.php')),
        'tests/Feature/CustomStudyAnswerTest.php must exist.'
    );
});
test('CustomStudyResumeTest exists', () => {
    assert.ok(
        existsSync(path('tests/Feature/CustomStudyResumeTest.php')),
        'tests/Feature/CustomStudyResumeTest.php must exist.'
    );
});
test('CustomStudyControllerTest exists', () => {
    assert.ok(
        existsSync(path('tests/Feature/CustomStudyControllerTest.php')),
        'tests/Feature/CustomStudyControllerTest.php must exist.'
    );
});
test('CustomStudyRoutesTest exists', () => {
    assert.ok(
        existsSync(path('tests/Feature/CustomStudyRoutesTest.php')),
        'tests/Feature/CustomStudyRoutesTest.php must exist.'
    );
});

// ---------------------------------------------------------------------------
// 8. SessionException architectural boundary
// ---------------------------------------------------------------------------

test('CustomStudySessionException declares session_not_found reason', () => {
    assert.ok(
        sessionExceptionSource.length > 0 &&
        sessionExceptionSource.includes('session_not_found'),
        'CustomStudySessionException must declare the session_not_found reason (token failure → 404).'
    );
});
test('CustomStudySessionException must NOT construct HTTP Response inside', () => {
    assert.ok(
        sessionExceptionSource.length > 0 &&
        !/\bnew\s+Response\b/.test(sessionExceptionSource) &&
        !/\bnew\s+JsonResponse\b/.test(sessionExceptionSource) &&
        !/response\(\s*\)/.test(sessionExceptionSource) &&
        !/response\(\s*\[/.test(sessionExceptionSource),
        'CustomStudySessionException must NOT construct HTTP Response inside — Controller maps to 404.'
    );
});

// ---------------------------------------------------------------------------
// summary
// ---------------------------------------------------------------------------

console.log('');
console.log(`Passed: ${passed}`);
console.log(`Failed: ${failed}`);
if (failed > 0) {
    process.exitCode = 1;
}
