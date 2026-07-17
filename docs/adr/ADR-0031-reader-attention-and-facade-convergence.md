# ADR-0031: Reader attention and facade convergence

Status: **Accepted / Production Closed (2026-07-17)**

## Decision

1. Reader content reserves right-side space only while the vocabulary sidebar is enabled, fits, is active, and is not hidden. `ReaderWorkspaceSizingService.js` owns this pure visibility/padding rule.
2. The sidebar is not mounted and `TextBlockGroup.vue` does not unhide it without an active selection.
3. Hover vocabulary remains one feature. Its automatic dictionary lookup, delay, and preferred-position controls are dependent settings and are hidden when the parent feature is off.
4. `TextBlockService` remains the public compatibility facade, while its constructor-owned `ReaderDataService` exclusively owns unique-word collection, reader projection/FSRS enrichment, and phrase-index projection. The unreachable duplicate implementations are deleted.

## Compatibility boundary

No endpoint, payload, database schema, tokenizer behavior, lemma/source semantics, WordSense/ReviewCard/ReviewLog ownership, FSRS rule, or reader completion behavior changes.

## Evidence

- Pure JS and structural guards: `ReaderWorkspaceSizingService.test.mjs`, `ReaderUxConvergenceGuard.test.mjs`, `ReaderArchitectureConvergenceGuard.test.mjs`.
- Reader characterization: 45 PHP tests / 169 assertions across FSRS highlights, phrase indexing, and EncounteredWord creation.
- Production build passed.
- Authenticated browser acceptance: `docs/testing/reader-attention-and-facade-browser-acceptance-2026-07-17.md`.
