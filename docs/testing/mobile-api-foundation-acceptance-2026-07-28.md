# Mobile API Foundation M1 Acceptance

Date: 2026-07-28
Roadmap: `docs/plans/cloud-first-mobile-product-and-technical-milestones-2026-07-28.md`
Architecture: `docs/adr/ADR-0032-mobile-api-foundation-and-idempotent-rating.md`; goal/browser handling: `docs/adr/ADR-0033-real-browser-acceptance-channel-fallback.md`, `docs/adr/ADR-0034-goal-mode-autonomous-decisions-and-deferred-acceptance.md`
Status: **Acceptance Deferred — Not Complete.**

## Scope

This acceptance covers only M1:

- additive `/api/v1/mobile` boundary;
- stable response envelope and bootstrap;
- device-bound Sanctum tokens and revocation;
- server-side `client_action_id` idempotency;
- formal Sense Review rating as the first mobile mutation;
- unchanged legacy Web rating contract.

It does not cover operation undo/redo, offline packages, synchronization, native
mobile UI, deployment, or any FSRS/product-semantics change.

## Requirement evidence

| M1 requirement | Evidence | Result |
|---|---|---|
| Additive versioned routes | `routes/api.php`; `php artisan route:list --path=api/v1/mobile` lists the four M1 routes while the legacy Web routes remain | Pass |
| Stable success/error envelope and metadata | `MobileApiResponse`; authentication, validation, bootstrap, rating, conflict, and internal-error assertions in `MobileApiFoundationTest` | Pass |
| Authenticated bootstrap/capabilities/readiness | `MobileBootstrapController`; authenticated and unauthenticated feature tests | Pass |
| Device identity without password/secret storage | `mobile_devices` migration/model and token-creation assertions | Pass |
| Sanctum token issue/reissue/revoke | `MobileAuthController`, `MobileDeviceController`, `EnsureActiveMobileDevice`; token binding/reissue/revoke tests | Pass |
| Same action/payload replays original result | `MobileIdempotencyService`; replay test proves one operation, one ReviewLog, and one card update | Pass |
| Replay remains stable after card state changes | state-change replay test proves the completed operation is read before rerunning current card eligibility | Pass |
| Same action/different payload conflicts | `IDEMPOTENCY_KEY_REUSED` test with no second ReviewLog or card update | Pass |
| Formal Sense rating first adopter | `MobileSenseReviewController` calls the existing `ReviewCardService` formal write path | Pass |
| Stable operation/log/card result | response assertions cover operation ID, client action ID, exact ReviewLog ID, replay marker, and current card summary | Pass |
| Existing Web payload compatibility | legacy `/reviews/senses/{id}/rate` feature test proves its existing unwrapped payload and database effect | Pass (automated) |
| Isolation, retry claim, revoke, rollback | user/language/device isolation, unique retry identity, revoked-token rejection, and forced scheduler-failure rollback tests | Pass |
| Real Web page rating | The official Browser connector refused `localhost` and explicitly prohibited workaround, indirect execution, raw browser commands, and alternate browser surfaces for the same result. ADR-0034 classifies this as a platform security refusal that repository rules cannot override. | **Deferred; still required before M1/final-goal completion** |

## Automated results

The following commands passed against the dedicated MySQL testing database:

- `php artisan test --filter=MobileApiFoundationTest` — 15 tests, 127 assertions.
- `php artisan test --filter=SenseReviewActionTransactionTest` — 14 tests, 76 assertions.
- `php artisan test --filter=ReviewFsrsTest` — 63 tests, 375 assertions.
- `php artisan test --filter=FsrsSchedulingServiceTest` — 9 tests, 46 assertions.
- `php artisan test --filter=WordSense` — 203 passed, 1 existing skipped, 873 assertions.
- `php artisan test --filter=TestingDatabaseHealthConfigTest` — 6 tests, 50 assertions.
- PHP syntax checks for all M1 PHP files.
- `git diff --check` for the M1 code and contract files.

The retry-claim test verifies that the database unique key accepts exactly one
row for a user/device/action/client-action identity. The production path then
locks that row and uses the database deadlock retry provided by
`DB::transaction(..., 3)`. Replay and rollback tests verify both sides of that
serialization contract without introducing a platform-specific process harness.

## Required real-browser closeout

The executing Agent must follow the failure classification in ADR-0034 before
the channel fallback ladder in ADR-0033 and
`docs/plans/mcp-chrome-local-smoke-playbook.md`. An ordinary connector failure
must not stop closeout after one attempt. The recorded explicit platform
prohibition remains authoritative, so this item stays
`Acceptance Deferred — Not Complete` until that prohibition is explicitly
changed by a higher-priority rule or an authorized platform channel becomes
available.

With the local app already running and an authenticated local test account:

1. Open `/reviews/senses` in an authorized real-browser channel.
2. Show one answer through the rendered UI.
3. Apply one visible rating (Good is recommended) through a DOM/user event.
4. Confirm the next-card/summary UI continues normally.
5. Read back the resulting ReviewCard and ReviewLog as supplementary evidence.
6. Undo or otherwise safely close the task-owned rating state when the acceptance plan requires restoration.
7. Record the final browser channel, any failed fallback attempts, Console, Network, and database deltas.

M1 must not be marked complete until this final page acceptance has evidence.
Under ADR-0034, M2 may start only after its Architecture Gate proves that it
depends exclusively on the verified Mobile API/idempotency contract and does not
modify or consume the deferred Web UI seam. This exception does not close M1 or
remove the browser requirement from the final M0–M18 audit.
