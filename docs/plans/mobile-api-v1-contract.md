# Mobile API V1 Contract

Status: M1–M4 contract, common envelope schema version 1
Base path: `/api/v1/mobile`
Architecture decisions: `docs/adr/ADR-0032-mobile-api-foundation-and-idempotent-rating.md`,
`docs/adr/ADR-0035-mobile-operation-ledger-and-linear-undo-redo.md` and
`docs/adr/ADR-0041-m3-mobile-download-packages-v1.md`, plus
`docs/adr/ADR-0042-m4-queued-action-sync-and-conflict-simulator.md`

## Common response

Every endpoint under the base path returns JSON.

Success:

```json
{
  "success": true,
  "data": {},
  "meta": {
    "server_time": "2026-07-28T00:00:00+00:00",
    "schema_version": 1,
    "minimum_client_version": "1.0.0"
  }
}
```

Failure:

```json
{
  "success": false,
  "error": {
    "code": "MACHINE_READABLE_CODE",
    "message": "Safe client message",
    "details": {}
  },
  "meta": {
    "server_time": "2026-07-28T00:00:00+00:00",
    "schema_version": 1,
    "minimum_client_version": "1.0.0"
  }
}
```

`details` is present only when useful, such as field validation errors.
Internal exception text, passwords, token hashes, provider keys, and stack traces
are never response fields.

## Authentication

Except for token creation, requests require:

```http
Authorization: Bearer <Sanctum plaintext token returned once at creation>
Accept: application/json
```

The token must have the `mobile` ability and must still be bound to the active
device record that issued it. Reissuing a token for the same user/device deletes
the prior token. Revoking the device deletes the active token.

## `POST /auth/tokens`

Rate limit: 6 requests per minute.

Request:

```json
{
  "email": "user@example.com",
  "password": "user-supplied password",
  "device_uuid": "UUID",
  "platform": "android|ios|web",
  "device_name": "optional, max 100 characters",
  "app_version": "required, max 50 characters"
}
```

Success: `201`.

```json
{
  "token": "plaintext token returned only in this response",
  "token_type": "Bearer",
  "device": {
    "device_uuid": "UUID",
    "platform": "android",
    "device_name": "Pixel",
    "app_version": "1.0.0",
    "last_active_at": "ISO-8601",
    "revoked": false
  }
}
```

Errors: `401 INVALID_CREDENTIALS`, `422 VALIDATION_ERROR`, `429 RATE_LIMITED`.

## `GET /bootstrap`

Success: `200`.

The data object contains:

- safe current-user identity (`id`, `name`, `email`);
- `current_language`;
- current device summary;
- `api_version` and `schema_version`;
- supported capability flags;
- read-only database readiness.

M1 capability flags advertise formal Sense Review and device revocation. Offline
queue/upload and operation undo/redo remain false until their milestones close.

After M2 closes, bootstrap also advertises `operation_ledger: true` and
`operation_undo_redo: true`. `offline_queue` remains false.

After M3 closes, bootstrap advertises `article_packages: true` and
`review_packages: true`. `offline_queue` remains false because M3 package
generation is read-only.

After M4 closes, bootstrap advertises `offline_queue: true`. This flag means the
server accepts the bounded queued-action contract below; it does not claim that
a native client already has local persistence or background synchronization.

Errors: `401 UNAUTHENTICATED`, `401 DEVICE_REVOKED`.

## M7 connected Android seams

All M7 endpoints remain connected-only and require the active device token.

### `GET /dictionary/lookup`

Query: required `term` string, maximum 100 characters. Returns at most ten
unique definitions from enabled local dictionaries:

```json
{
  "term": "friendly",
  "definitions": ["友好的"],
  "local_only": true,
  "read_only": true
}
```

This endpoint never calls an external dictionary/AI provider and never writes
WordSense, ReviewCard, ReviewLog or FSRS data.

### `POST /word-senses`

Creates one deliberate manual WordSense through the existing
`WordSenseService::createManualSense` owner. Required fields are `lemma`, `pos`
and `sense_zh`; optional surface, English meaning and chapter/sentence context
use the same meanings as the existing Web manual-sense flow. Success is `201`
with `word_sense` in the common data envelope.

The server derives user and language from the authenticated token. The client
cannot submit ownership, status, ReviewCard or FSRS fields.

### `GET /summary`

Returns read-only `today.reviewed_today_count`,
`today.introduced_today_count`, `active_card_count`, `due_now_count`,
`generated_at` and `read_only: true`, all scoped to the token user and selected
language.

## `DELETE /devices/{deviceUuid}`

The path UUID must identify a device owned by the current authenticated user.
Success revokes that device and its active token and returns `200` with:

```json
{
  "device": {
    "device_uuid": "UUID",
    "revoked": true
  }
}
```

Errors: `401 UNAUTHENTICATED`, `401 DEVICE_REVOKED`, `404 DEVICE_NOT_FOUND`.

## `POST /review-cards/{reviewCard}/ratings`

Request:

```json
{
  "rating": "again|hard|good|easy",
  "client_action_id": "UUID",
  "review_session_id": "optional UUID",
  "review_duration_ms": 1250
}
```

`review_duration_ms` is optional and must be between 0 and 600000.

The card must belong to the authenticated user, match the user's selected
language, target a confirmed WordSense, and be active, enabled, and not buried.
The controller does not mutate FSRS or ReviewLog directly; the formal
`ReviewCardService` path remains the owner.

Success: `200`.

```json
{
  "operation_id": "server UUID",
  "client_action_id": "client UUID",
  "review_log_id": 123,
  "card": {
    "id": 10,
    "target_type": "sense",
    "target_id": 20,
    "fsrs_state": "learning",
    "fsrs_due_at": "ISO-8601 or null",
    "fsrs_stability": 1.2,
    "fsrs_difficulty": 5.0,
    "fsrs_reps": 1,
    "fsrs_lapses": 0,
    "fsrs_last_reviewed_at": "ISO-8601 or null",
    "lifecycle_state": "active",
    "lifecycle_version": 1
  },
  "replayed": false
}
```

### Idempotency

The retry identity is:

`authenticated user + active device + action type + client_action_id`.

- First valid execution stores the canonical request hash and exact successful
  response in the same transaction as the rating.
- Same identity and same canonical payload returns the stored response with
  `replayed: true`; it does not write another ReviewLog or advance FSRS again.
- Replay remains valid if the card's current state later changes.
- Same identity with a different payload returns `409
  IDEMPOTENCY_KEY_REUSED`.
- Validation/access failures and unexpected transaction failures do not leave a
  completed claim.

Other errors: `404 REVIEW_CARD_NOT_FOUND`, `422 VALIDATION_ERROR`, `500
INTERNAL_ERROR`.

## M3 download packages

M3 package payloads use `schema_version=mobile_download_package_v1` inside the
common Mobile API envelope. All endpoints require an active device token and
scope every resource to the authenticated user and selected language.

### `GET /article-packages`

Query: `page` and `per_page` (`1..20`, default 20). The response contains
page-number pagination and one summary per processed Book:

- Book identity, name, language and cover reference;
- deterministic whole-package `content_version` / `content_checksum`;
- processed Chapter count and manifest endpoint.

### `GET /article-packages/{book}`

Query: `chapter_page` and `chapters_per_page` (`1..100`, default 50). The
response contains the deterministic whole-package checksum plus paged,
id-ordered processed Chapter descriptors. Each descriptor contains its own
content version/checksum, exact token count and shard endpoint.

Any Book/Chapter source, processed text, saved sentence translation or included
bound confirmed WordSense summary change changes the affected checksum.
Clients replace cached content when a version differs; revision merging is not
supported.

### `GET /article-packages/{book}/chapters/{chapter}`

Query: opaque `cursor` and `token_limit` (`1..1000`, default 500). The response
contains a token shard, stable token/sentence/section identities, matching
sentence translations and matching confirmed WordSense summaries. Data before
the common envelope is bounded to 1.5 MiB. `next_cursor` is null on the final
shard.

Errors include `404 ARTICLE_PACKAGE_NOT_FOUND`, `409 ARTICLE_PACKAGE_CHANGED`,
`409 INVALID_PACKAGE_SOURCE`, `422 INVALID_PACKAGE_CURSOR` and `422
VALIDATION_ERROR`.

### `GET /review-packages/short-term`

Query:

- `horizon_days`: `0..30`, default 7;
- `limit`: `1..100`, default 50;
- `cursor`: opaque continuation bound to the current user/language.

The immutable cursor carries `generated_at/as_of`, horizon and the last
`(fsrs_due_at, review_card_id)` key. The package selects confirmed,
FSRS-enabled, effectively active Sense cards due by the horizon, ordered by due
time then id. Each item contains a projected display payload, current FSRS and
lifecycle snapshot, and deterministic item version. Package generation never
rates a card or writes a ReviewLog/Operation. M3 originally returned
`offline_rating_upload_supported: false` and bootstrap `offline_queue: false`
while packages were read-only. After M4 acceptance, both flags are true because
the server can accept queued ratings through `POST /sync/actions`; native
storage/background execution remain M7/M8 work.

Errors include `409 INVALID_PACKAGE_SOURCE`, `422 INVALID_PACKAGE_CURSOR` and
`422 VALIDATION_ERROR`.

## M4 queued-action sync

### `POST /sync/actions`

The request body is limited to 1 MiB and contains 1–100 actions:

```json
{
  "batch_id": "UUID",
  "actions": [{
    "client_action_id": "UUID",
    "type": "sense_review.rating",
    "occurred_at": "2026-07-29T08:30:00.000Z",
    "sequence": 1,
    "payload": {
      "review_card_id": 10,
      "rating": "good",
      "review_session_id": "optional UUID",
      "review_duration_ms": 1250
    }
  }]
}
```

`occurred_at` is a valid ISO-8601 timestamp with an explicit `Z` or numeric
offset and up to microsecond precision. It must be no more than five minutes in
the future or thirty days old. `sequence` is a positive, monotonically
increasing device integer.

Supported action payloads are:

- `sense_review.rating`: `review_card_id`, `rating`, and optional
  `review_session_id` / `review_duration_ms`;
- `word_sense.update`: `word_sense_id`,
  `expected_word_sense_version`, and a non-empty `changes` object restricted to
  POS, meanings, examples, aliases and collocations;
- `word_sense.delete`: `word_sense_id` and
  `expected_word_sense_version`.

The expected WordSense version is the `sha256:` value exposed by M3 article and
review packages. Update/delete lock the user/language-scoped WordSense and
compare that version before writing.

A structurally valid batch returns HTTP `200` even when individual actions
fail. The server processes actions independently in deterministic
`(occurred_at, sequence, original_index)` order and returns results in original
request order:

```json
{
  "batch_id": "UUID",
  "status": "completed|partial|failed",
  "counts": {
    "total": 2,
    "succeeded": 1,
    "failed": 1,
    "replayed": 0
  },
  "results": [{
    "original_index": 0,
    "processed_order": 1,
    "client_action_id": "UUID",
    "type": "word_sense.update",
    "outcome": "applied|replayed|conflict|rejected|retryable",
    "http_status": 200,
    "replayed": false,
    "operation_id": "UUID or null",
    "data": {},
    "error": null
  }],
  "retry_policy": {
    "strategy": "exponential_backoff",
    "base_delay_ms": 1000,
    "max_delay_ms": 30000,
    "retryable_codes": ["INTERNAL_ERROR"]
  }
}
```

`original_index` and `processed_order` are zero-based. `succeeded` counts
applied and replayed successes. `replayed` counts every stored response replay,
including a replayed domain conflict, so it is not necessarily a subset of
`succeeded`.

The retry identity remains authenticated user + active device + action type +
`client_action_id`; `batch_id` is metadata only. An exact retry in another
batch replays the stored success or domain result. Reusing the identity with a
different canonical action returns `IDEMPOTENCY_KEY_REUSED`. Unexpected
failures roll back the claim and return retryable `INTERNAL_ERROR` guidance.

Stable non-retryable action codes include `VALIDATION_ERROR`,
`ACTION_TIME_OUT_OF_RANGE`, `OUT_OF_ORDER_ACTION`, `REVIEW_CARD_NOT_FOUND`,
`WORD_SENSE_NOT_FOUND`, `WORD_SENSE_DELETED`, `STALE_WORD_SENSE`, and
`IDEMPOTENCY_KEY_REUSED`. A queued rating is rejected as out of order whenever
the card has a newer accepted formal rating, including one submitted through a
Web entry point.

Invalid outer structure returns `422 VALIDATION_ERROR`; an oversized request
returns `413 PAYLOAD_TOO_LARGE`; unauthenticated/revoked devices are rejected
before any action executes.

## M2 operation ledger

M2 adds an account/language-isolated linear operation history for Mobile formal
Sense ratings. The operation uses the exact M1 `operation_id`; the idempotency
claim and domain operation are separate records with separate responsibilities.

### `GET /operations`

Optional query:

- `review_session_id`: UUID;
- `limit`: integer `1..100`, default `20`.
- `before_sequence`: positive integer cursor returned by the previous page.

Success returns newest-first operations with:

```json
{
  "operations": [{
    "operation_id": "UUID",
    "operation_type": "sense_review.rating",
    "status": "applied|undone|superseded",
    "version": 1,
    "review_session_id": "UUID or null",
    "review_card_id": 10,
    "review_log_id": 123,
    "source_device_uuid": "UUID",
    "can_undo": true,
    "can_redo": false,
    "created_at": "ISO-8601",
    "updated_at": "ISO-8601"
  }],
  "next_before_sequence": 123
}
```

The endpoint returns only the authenticated user's selected-language
operations. `can_undo` and `can_redo` reflect the current top of each linear
stack, not merely the row status. `next_before_sequence` is null when no older
page remains.

### `POST /operations/{operationId}/undo`

### `POST /operations/{operationId}/redo`

Request:

```json
{
  "client_action_id": "UUID",
  "expected_version": 1
}
```

Both endpoints use the M1 client-action idempotency service. A same-key,
same-payload retry returns the original result with `replayed: true`; a changed
payload returns `409 IDEMPOTENCY_KEY_REUSED`.

Success returns the serialized operation, current card summary and
`replayed`. Undo restores the ReviewLog before-snapshot; redo restores the
after-snapshot. Neither transition calls the scheduler or creates a new
ReviewLog.

Stable operation errors:

- `404 OPERATION_NOT_FOUND`;
- `409 OPERATION_VERSION_CONFLICT`;
- `409 OPERATION_NOT_LATEST`;
- `409 OPERATION_NOT_UNDOABLE`;
- `409 OPERATION_NOT_REDOABLE`;
- `409 OPERATION_STATE_CHANGED`;
- `409 OPERATION_TARGET_UNAVAILABLE`.

New ratings invalidate the redo branch in the same session/device stack by
marking remaining undone operations `superseded`.

## M9 iOS text document import

### `POST /imports/text`

This additive endpoint requires an active mobile device token and the current
selected language `english`. It accepts one deliberate `.txt` import; it does
not add a mobile ebook/subtitle/archive pipeline.

Request:

```json
{
  "client_action_id": "uuid",
  "file_name": "reader.txt",
  "content": "UTF-8 English text, 1–200000 characters",
  "book_name": "Reader",
  "chapter_name": "Imported text"
}
```

The client limits the selected file to 200000 bytes before decoding. The server
rejects a non-`.txt` name, blank/whitespace-only content, an oversized string,
or empty/oversized names. Ownership, language, chunk size, processing method and
new-book selection are server-owned. The endpoint delegates to the existing
`ImportService` with chunk size 3000, `detailed` processing and a new book.

Success is `201` and includes `operation_id`, `client_action_id`, names,
`processing_mode` (`tokenizer` or `fallback`) and `replayed`. Retry identity is
`user + device + library.text_import + client_action_id`. An exact replay
returns the original response without a second import; changed payload reuse is
`409 IDEMPOTENCY_KEY_REUSED`. The UI never retries an ambiguous upload
automatically and retains the same action id for a manual retry of the unchanged
selected bytes and names.

Other errors: `401 UNAUTHENTICATED`, `401 DEVICE_REVOKED`,
`409 ENGLISH_LANGUAGE_REQUIRED`, `422 VALIDATION_ERROR`,
`500 TEXT_IMPORT_FAILED`.

## Compatibility

This contract does not apply to existing Web endpoints. In particular,
`POST /reviews/senses/{reviewCard}/rate` retains its existing request and
unwrapped response payload. Mobile clients must not depend on Web endpoint
payloads, and Web callers are not required to send mobile idempotency fields.
The legacy Web undo endpoint remains a Web-action compatibility entry and is
not a Mobile operation-ledger API. Mobile clients must use the M2 undo/redo
endpoints; an out-of-band card mutation is rejected by the next Mobile
transition as `OPERATION_STATE_CHANGED`.
