# English Fallback Tokenizer Service — Browser Acceptance

Date: 2026-07-23

Status: Accepted / Production Closed

Implementation commit: `65167c1`

## Scope and result

Phase 6M moved the production English Python-down fallback tokenizer from `TextBlockService` to the pure `EnglishFallbackTokenizerService`. `TextBlockService` still owns the Python-first decision, the private fallback compatibility facade, protected ECDICT hooks, doctor-only heuristic, structural mapping, import pipeline, and Reader facade.

The change crosses one implementation seam and changes no route, request, payload, model, schema, public constructor, provider, or learning-data write.

Quantitative result:

- `TextBlockService.php`: 1,382 → 1,077 lines.
- New pure owner: 232 lines.
- Removed from the facade: token splitting/construction, production conservative lemma rules, and the full irregular map.

## Automated evidence

- `EnglishFallbackTokenizerServiceTest`: 6 tests / 41 assertions.
- Existing `TextBlockFallbackTokenizerTest`: 7 / 45.
- Combined protected matrix: 115 / 641. It covered testing DB health/config, both fallback suites, tokenizer doctor parsing, EncounteredWord creation/enrollment, phrase indexing, Reader FSRS/read-only behavior, and protected lookup/UI guards.
- PHP syntax checks passed for both services and the new test.
- `npm run development`: compiled successfully in 6.73s.
- `git diff --check`: passed for the implementation scope.

The characterization evidence preserves token field order, sentence-index behavior (including delimiter-capture increments), blank-input exception, marker handling, conservative suffix behavior, ECDICT callback overrides, and representative irregular mappings.

## Official Browser acceptance

An isolated testing-MySQL user, book, and English chapter were prepared while `PYTHON_CONTAINER_NAME` pointed to `http://127.0.0.1:1`, forcing the production fallback path.

Prepared tokens:

- `Watches` → `watch`, sentence index `0`
- `children` → `child`, sentence index `0`
- `.` → `.`, sentence index `0`

Official Browser verification:

- 1920×1000: chapter rendered; hover state appeared; clicking `Watches` showed `watches → watch` and the original sentence.
- 900×900: clicking `children` showed surface `children`, lemma/search term `child`, stage actions, and the original sentence.
- Both viewports had `0` horizontal overflow.
- The click produced the established `lookup_count +1` effect only.
- Chapter `read_count`, EncounteredWord stages/read counts, WordSense count, ReviewCard count, and ReviewLog count were otherwise unchanged; protected counts remained `0 / 0 / 0`.
- The automation-owned page was closed, the temporary viewport was reset, the fixture was cleaned twice, and no Browser pages remained.

## Scoped five-axis review

- Responsibility: production English fallback rules now have one pure owner.
- Seam: only the existing private facade delegates; Python transport and downstream processing did not move.
- Coupling: two read-only callbacks preserve the protected ECDICT override seam without giving the pure service DB access.
- Risk: fallback/import/Reader behavior is covered directly, through the old facade tests, through the protected matrix, and through real fallback-prepared browser data.
- Scope/ADR: only the frozen three implementation/test files changed; no ADR is required because public and data semantics are unchanged.

## Phase 6 completion audit

The authoritative remaining Phase 6 backend target was tokenizer/fallback convergence. It is now closed:

- `ReaderDataService` owns read-side preparation and phrase-index mapping.
- `EncounteredWordCreationService` owns new encountered-word writes.
- `EnglishFallbackTokenizerService` owns the production English fallback.
- `TextBlockService` remains the compatibility/import/Reader facade, including the characterized phrase-occurrence write algorithm; moving that algorithm again is not required by the accepted remaining target and would open another seam without a current defect.

Phase 6 Reader frontend and backend governance are therefore Accepted / Production Closed. The next roadmap phase is Phase 7 AI Study Card service convergence; real external provider use remains subject to its environment gate.
