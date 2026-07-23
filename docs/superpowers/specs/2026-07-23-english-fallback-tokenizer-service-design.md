# English Fallback Tokenizer Service Design

Date: 2026-07-23

Status: Accepted / Production Closed (`65167c1`)

## Goal

Move the production English Python-down fallback tokenization and conservative lemma rules out of `TextBlockService.php` while preserving its constructor, private fallback facade, protected ECDICT test hooks, token shape, exceptions, import order, structural-marker mapping, and every observable Reader/import result.

## Existing behavior to preserve

- `TextBlockService::tokenizeRawText()` still calls the Python tokenizer first with the same timeout, URL, payload, and error handling.
- Only English falls back after a Python failure; every other language still rethrows.
- The private `fallbackEnglishTokenize(string): array` method remains present so existing reflection-based characterization tests and internal compatibility are unchanged.
- Sentence splitting, token regex, sentence-index increments, blank-text exception, token fields/order, alphabetic/PUNCT classification, lowercasing, marker handling, ultra-safe suffix rules, and the full irregular mapping remain exact.
- ECDICT-gated `-ies` handling still calls the existing protected `ecdictAvailable()` and `lemmaInEcdict()` hooks, including test-subclass overrides.
- `suggestEnglishLemmaForDoctorOnly()` and ECDICT DB access stay in `TextBlockService`; the doctor-only heuristic is not part of the production fallback import path.
- `mapStructuralTokens()` remains in `TextBlockService` after fallback output, so paragraph/newline/section behavior is unchanged.

## Boundary

New pure owner:

- `EnglishFallbackTokenizerService`
  - sentence/token splitting for the Python-down English path;
  - fallback token construction and token-field shape;
  - conservative import lemma rules;
  - hand-curated irregular verb/noun mapping.

The service receives two read-only callbacks for ECDICT availability and lemma membership. It does not access Laravel facades, DB, HTTP, Auth, files, Vue, queues, learning data, or external providers.

Existing facade/bridge owner:

- `TextBlockService`
  - Python request and English-only fallback decision;
  - private `fallbackEnglishTokenize()` compatibility facade;
  - protected ECDICT hooks and their DB/cache behavior;
  - doctor-only heuristics and safe-lemma helper;
  - structural-token mapping, language-specific post-processing, phrase/write/read-data facades, and final Reader payload.

`TextBlockService::englishIrregularLemma()` remains as a private doctor compatibility adapter delegating to the new owner.

## Scope

Allowed:

- `app/Services/EnglishFallbackTokenizerService.php`
- `app/Services/TextBlockService.php`
- `tests/Unit/EnglishFallbackTokenizerServiceTest.php`
- `tests/Unit/TextBlockFallbackTokenizerTest.php` only if a source/integration assertion is needed; behavior assertions may not be weakened
- Phase 6M design, plan, acceptance, index, handoff, master-plan, roadmap, and backend-closure documents

Forbidden:

- changing `TextBlockService` constructor/public methods/properties, `tokenizeRawText()` call order, Python URL/timeout/payload, fallback eligibility, logs, or exceptions;
- changing any regex, token field/value/order, sentence index, irregular entry, suffix rule, strict/loose behavior, ECDICT query/cache, or test override hook;
- moving doctor-only heuristics into the production import path;
- changing ChapterService, import routes/jobs/controllers, Reader payload, tokenizer service, frontend, Vuex, or database schema;
- changing EncounteredWord, WordSense, ReviewCard, ReviewLog, FSRS, source context, AI provider, secret, cost, or external request behavior;
- generic tokenizer interfaces, repositories, DTOs, dependency injection changes, new packages, migration, or refactoring adjacent phrase/ReaderData code.

## Risk, seam, and architecture gate

Import/tokenizer and Reader data are protected high-risk areas. This slice crosses one seam only: production English fallback values move from the `TextBlockService` implementation body into a pure collaborator, while the facade, fallback decision, ECDICT hooks, and downstream pipeline remain fixed.

No public API, payload, DB model, accepted ADR, or product behavior changes. A new ADR is not required.

Fresh-context adversarial review:

- Moving the Python HTTP bridge together with fallback rules would cross transport and fallback seams: rejected.
- Injecting a tokenizer interface through the public constructor would change the facade and add abstraction: rejected.
- Removing the private facade or protected hooks would break established characterization/test-subclass compatibility: rejected.
- Generalizing fallback to non-English languages would expand product scope: rejected.
- Improving lemma heuristics, fixing doctor-only behavior, or pruning irregular entries would change semantics: rejected.
- Moving ECDICT DB access into the pure service would add data capability and make override compatibility harder: rejected.
- Deleting the always-on `ReaderDataService` fallback branches is a separate seam: rejected.

A pure English fallback collaborator plus thin compatibility adapters is the smallest safe backend convergence.

## Verification

- RED before `EnglishFallbackTokenizerService.php` exists.
- Direct tests for token splitting/shape, sentence indexes, blank input, conservative rules, ECDICT callbacks, irregular entries, and input immutability.
- Existing `TextBlockFallbackTokenizerTest` stays green unchanged in behavior.
- Integration/source guard proving `TextBlockService` keeps the private facade/protected hooks and delegates production fallback without retaining inline token regex or irregular table.
- `TextBlockPhraseIndexingTest`, `ReaderFsrsHighlightTest`, testing DB health/config, protected WordSense/lookup UI guards, and full relevant fallback/import tests.
- Official-browser acceptance on an isolated English chapter prepared with the Python endpoint intentionally unavailable, at wide and narrow viewports.
- Protected-write snapshots, double cleanup, diff checks, line/owner audit, and five-axis scoped review.

## Acceptance

Accept only if direct and facade fallback outputs are byte-for-byte structurally equal for characterized inputs, Python-first behavior and all import/Reader contracts remain unchanged, the real fallback-prepared chapter works at both viewports, and protected learning data remains unchanged.
