# M3 Mobile Download Packages V1 — Acceptance

Date: 2026-07-29
Result: Accepted / Closed

## Accepted behavior

- Active Mobile API devices can list article-package summaries, fetch a
  deterministic Book manifest, and download bounded Chapter token shards.
- Article checksums include processed/source content, saved translations and
  bound confirmed WordSense summaries. A changed Chapter rejects an old cursor
  with `ARTICLE_PACKAGE_CHANGED`.
- Token, sentence and section identities are stable inside the Chapter content
  version. Chapter manifests page at up to 100 descriptors; token shards page at
  up to 1,000 tokens and no more than 1.5 MiB before the common envelope.
- The short-term package contains only confirmed, effectively active,
  FSRS-enabled Sense cards due inside the 0–30 day horizon. It is ordered by due
  time/card id and contains projected display content plus the current FSRS and
  lifecycle snapshot.
- Review continuation cursors preserve `as_of`, horizon and package version and
  are cryptographically opaque and bound to the authenticated user/language.
- Package generation is read-only. `offline_queue` and offline rating upload
  remain false.
- Foreign users, other selected languages, wrongly nested Chapters, corrupt
  processed text, invalid cursors and over-limit input return the common safe
  Mobile API envelope.

## Automated evidence

- Focused M3 matrix: 6 passed, 91 assertions.
- Scale fixtures:
  - 5,000 processed tokens, with a 1,000-token bounded shard;
  - 250 due Sense cards, with a 100-card page and query-count guard;
  - response-size guards for both package families.
- Mobile API + operation-ledger matrix: 32 passed, 345 assertions.
- Protected FSRS/WordSense matrix: 276 passed, 1 external-tokenizer-dependent
  test skipped, 1,300 assertions.
- Documentation/architecture guards: 7 Node test suites passed, including the
  38-check backend vertical-slice guard and 84-check architecture-doc guard.
- PHP lint and Mobile route discovery passed for all M3 services/controllers
  and all four package routes.
- Read-only assertions compare row counts, timestamps and the complete relevant
  ReviewCard scheduling/lifecycle snapshot before and after package generation.

The single skipped morphology import test requires the external Python
tokenizer on `127.0.0.1:8678`; it is unrelated to package generation and did not
defer any M3 acceptance behavior.

## Browser acceptance

No visible UI was introduced in M3. The deliverable is a bearer-authenticated
JSON API, so Feature tests exercise the real Laravel middleware, Sanctum device
binding, routing, serialization and testing MySQL boundaries directly. Putting
a plaintext bearer token into a browser URL/history would weaken the credential
boundary and would not add UI evidence. Real-browser acceptance therefore does
not apply to this API-only slice.

## Adversarial closeout

Two substantive findings were fixed before closure:

1. review cursors now bind user id and selected language, preventing a valid
   cursor from being reused across principals to create misleading pagination;
2. article manifests now page Chapter descriptors and both package families
   enforce hard payload budgets, avoiding an unbounded response despite bounded
   item counts.

The follow-up review found no remaining behavior, isolation, write-boundary or
payload issue requiring a contract change.
