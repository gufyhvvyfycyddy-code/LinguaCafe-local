# ADR-0032: Mobile API Foundation and Idempotent Formal Rating

## Status

Accepted — 2026-07-28

## Context

The cloud-first mobile roadmap keeps Laravel and the central database authoritative. Mobile clients need a stable versioned interface, revocable device identity, and a server guarantee that a retried formal rating creates at most one ReviewLog and advances one ReviewCard once.

The existing web rating endpoint and payload are already in production use. `ReviewCardService` is the formal-rating owner and `FsrsSchedulingService` is the scheduling owner. M1 must add a mobile adapter without replacing either owner or changing FSRS semantics.

## Decision

### API interface

Add `/api/v1/mobile` as an additive route group. Every mobile response uses:

```json
{
  "success": true,
  "data": {},
  "meta": {
    "server_time": "ISO-8601",
    "schema_version": 1,
    "minimum_client_version": "1.0.0"
  }
}
```

Errors replace `data` with:

```json
{
  "success": false,
  "error": {
    "code": "MACHINE_READABLE_CODE",
    "message": "Safe client message",
    "details": {}
  },
  "meta": {}
}
```

The first endpoints are:

- `POST /api/v1/mobile/auth/tokens`
- `GET /api/v1/mobile/bootstrap`
- `DELETE /api/v1/mobile/devices/{deviceUuid}`
- `POST /api/v1/mobile/review-cards/{reviewCard}/ratings`

Existing web routes and payloads remain unchanged.

### Authentication and device identity

Use the installed Laravel Sanctum capability. `User` gains `HasApiTokens`. Token creation validates email/password, is rate-limited, returns the plaintext token only once, and replaces the prior token for the same user/device record.

`mobile_devices` stores user ownership, opaque client device UUID, platform, optional display name, app version, the active personal-access-token id, last activity, and revocation time. It never stores a plaintext password, plaintext token, provider key, or external credential.

Every authenticated mobile route requires both a valid Sanctum token and an active device row bound to that exact token. Revocation marks the device revoked and deletes its active token.

### Bootstrap/readiness

The authenticated bootstrap endpoint returns the current user's safe identity fields, selected language, device summary, supported capabilities, API/schema versions, server time, and read-only readiness. M1 readiness reports only facts available without mutating data or calling an external provider.

### Idempotent mutation module

`mobile_client_actions` is the server claim and replay store. Its unique identity is:

`user_id + mobile_device_id + action_type + client_action_id`

It stores a server-generated `operation_id`, canonical request hash, status, HTTP status, and successful response body.

The idempotency module:

1. canonicalizes the validated mutation input and hashes it;
2. claims the unique row inside the same database transaction as the domain mutation;
3. locks the claimed row;
4. returns the stored result when the same key and hash were completed before;
5. returns HTTP 409 `IDEMPOTENCY_KEY_REUSED` when the same key carries a different hash;
6. executes the callback once when no completed claim exists;
7. stores the response before committing;
8. rolls back both the claim and all domain writes on unexpected failure.

Request validation happens before a client action is claimed. Domain access and
eligibility checks run only inside the first execution callback: a rejection
rolls back the claim and may be retried after the request or state is corrected,
while a completed action is replayed from its stored result even if the card's
current eligibility later changes.

### Formal rating first adopter

The mobile rating adapter accepts:

- `rating`: `again|hard|good|easy`
- `client_action_id`: UUID, required
- `review_session_id`: UUID, optional
- `review_duration_ms`: integer `0..600000`, optional

It resolves only the authenticated user's selected-language, active, confirmed-sense ReviewCard. It delegates the mutation to `ReviewCardService`; it never writes ReviewCard, ReviewLog, or FSRS fields directly.

`ReviewCardService` exposes a rating outcome containing the exact updated ReviewCard and exact created ReviewLog. The existing `recordReview()` interface remains and delegates to the same implementation, preserving all legacy callers.

The mobile success result contains stable `operation_id`, `client_action_id`, `review_log_id`, and a current card summary. Replaying the same request returns the same identifiers and summary without another ReviewLog or FSRS update.

## Compatibility and Non-goals

- No existing web endpoint is removed or moved.
- Web callers that omit mobile fields retain their current request and response payloads.
- FSRS calculation, rating meanings, WordSense binding, ReviewCard identity, lifecycle eligibility, and undo behavior are unchanged.
- M1 does not add an offline queue, operation-ledger undo/redo, article/review packages, Android/iOS UI, or real provider calls.
- M1 does not run migrations against the developer or production database.

## Failure Semantics

- invalid credentials: `401 INVALID_CREDENTIALS`;
- absent/invalid/revoked token or device: `401 UNAUTHENTICATED` or `401 DEVICE_REVOKED`;
- validation failure: `422 VALIDATION_ERROR`;
- inaccessible/non-sense/non-current-language card: `404 REVIEW_CARD_NOT_FOUND`;
- reused key with a different payload: `409 IDEMPOTENCY_KEY_REUSED`;
- unexpected failure: `500 INTERNAL_ERROR`, with no internal exception text.

## Consequences

- Mobile clients receive one stable adapter while the existing web application remains compatible.
- Device revocation has a concrete server-side owner.
- At-most-once formal-rating effects are enforced by a database unique key and transaction, not client timing.
- Two additive tables and a small mobile adapter surface are introduced.
- Later operation-ledger and offline milestones can reuse `operation_id` and client-action identity without changing M1 rating semantics.

## Validation

- feature tests for token creation, bootstrap, safe envelope, device binding/revocation, and user/language isolation;
- idempotency tests for replay (including after card-state change), changed payload conflict, unique claim, transaction rollback, and no duplicate ReviewLog/ReviewCard advancement;
- legacy web rating contract regression;
- `ReviewFsrsTest`, `FsrsSchedulingServiceTest`, and relevant Sense/WordSense tests;
- route inspection and real web Sense Review browser acceptance;
- `git diff --check`.
