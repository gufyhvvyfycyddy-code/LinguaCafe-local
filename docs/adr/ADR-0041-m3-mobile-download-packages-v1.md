# ADR-0041: M3 Mobile Download Packages V1

## Status

Accepted under the current roadmap goal authorization

## Context

M1/M2 established the authenticated `/api/v1/mobile` envelope, device binding,
idempotent formal rating path and server operation ledger. M3 must make article
and short-term review data downloadable and testable before a native client or
local mobile database exists.

Laravel and MariaDB remain authoritative. The package API must therefore expose
bounded, verifiable read models without introducing a second collection,
scheduler or offline-write protocol. Anki's official sync model keeps a
collection copy on each client/server and merges collection changes. That is
deliberately broader than LinguaCafe's cloud-first, limited-offline roadmap:

- <https://docs.ankiweb.net/syncing.html>
- <https://docs.ankiweb.net/sync-server.html>

## Decision

1. M3 adds only authenticated, device-bound, read-only mobile endpoints:
   - `GET /api/v1/mobile/article-packages`;
   - `GET /api/v1/mobile/article-packages/{book}`;
   - `GET /api/v1/mobile/article-packages/{book}/chapters/{chapter}`;
   - `GET /api/v1/mobile/review-packages/short-term`.
   No M3 endpoint accepts ratings, edits or acknowledgement writes.
2. An article package is one authenticated user's processed Book in the
   currently selected language. The list endpoint is page-number paginated with
   a maximum of 20 books. A package manifest contains ordered Chapter
   descriptors paged at no more than 100 per response and a deterministic
   whole-package SHA-256 content version/checksum.
3. A Chapter descriptor checksum covers its identity, source/processed text,
   subtitle timing, saved sentence translations and bound confirmed
   WordSense summaries. Any authoritative article, translation or included
   sense change therefore changes the Chapter checksum and containing package
   version. A client whose version differs must discard the affected cached
   package/shard and download it again; M3 does not merge article revisions.
4. Chapter content is token-sharded with an opaque cursor and a requested
   `token_limit` of 1–1000 (default 500). Each response is additionally bounded
   to 1.5 MiB before the common envelope. An individual source token that cannot
   fit is rejected as invalid package source data rather than truncated.
5. Token identity is stable inside a Chapter content version:
   `chapter:{chapter_id}:token:{absolute_index}`. Sentence identity is
   `chapter:{chapter_id}:sentence:{source_sentence_identity}`. Section identity
   uses an explicit processed-token section index when present, otherwise the
   deterministic paragraph/section sequence derived from structural tokens.
   Identity is intentionally invalidated when the Chapter checksum changes.
6. Chapter shards carry only the translations and bound confirmed WordSense
   summaries relevant to the shard's sentence identities, plus unlocated
   summaries on the first shard. All related rows are scoped by the same
   user/language/chapter before serialization.
7. A short-term review package is a read-only snapshot of confirmed,
   FSRS-enabled, effectively active Sense cards whose `fsrs_due_at` is at or
   before `as_of + horizon_days`. `horizon_days` defaults to 7 and is limited to
   0–30. It includes overdue/currently due cards and near-future cards, but does
   not make them rateable offline in M3.
8. Review packages use an opaque cursor that carries one immutable UTC `as_of`,
   horizon and the last `(due_at, id)` key. Pages are ordered by due time then
   card id, limited to 1–100 cards (default 50), and preserve a stable package
   version/generated time across continuation pages. Cursors are bound to the
   authenticated user and selected language. Cards that change after
   `as_of` are not historical copies; their current snapshot may make a later
   page ineligible. M4 owns queued-action and conflict semantics.
9. Review display content reuses the existing batch Sense review serializer,
   then projects only mobile display fields. Scheduling state is a separate
   explicit snapshot containing state, due time, stability, difficulty, reps,
   lapses, last-reviewed time, lifecycle state/version and FSRS enablement.
10. Package generation must not create or update WordSense,
    WordSenseOccurrence, ReviewCard, ReviewLog, EncounteredWord, Operation,
    MobileClientAction or article rows. User/language isolation is applied to
    every root and related query.
11. Mobile bootstrap advertises `article_packages=true` and
    `review_packages=true` after these endpoints and their acceptance matrix
    pass. `offline_queue` remains false.
12. Corrupt/oversized processed text, invalid cursors and cross-scope resources
    use the common mobile error envelope. Missing or foreign Book/Chapter
    resources are indistinguishable `404` responses.

## Compatibility and exclusions

- No native Android/iOS storage, background download, media bundle or offline
  rating upload.
- No Anki collection database, full sync protocol, `.apkg` semantics,
  Deck/Note Type/template ownership or client-authored FSRS state.
- No migration, backfill or new data authority.
- Existing Reader, Web review, Mobile rating and operation-ledger contracts are
  unchanged.

## Verification

- contract, authentication, device, user/language and Book/Chapter nesting
  tests;
- deterministic checksum/version and mutation invalidation tests;
- token/sentence/section identity and translation/sense-summary shard tests;
- cursor, payload, corrupt-source and invalid-request tests;
- short-term eligibility, lifecycle, ordering, immutable continuation and
  current scheduling snapshot tests;
- read-only before/after table-count and model-timestamp assertions;
- large-article and large-due-queue query/payload performance tests;
- protected Mobile API, Review FSRS, scheduling, WordSense and operation-ledger
  regressions.
