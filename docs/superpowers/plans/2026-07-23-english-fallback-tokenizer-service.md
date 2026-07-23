# English Fallback Tokenizer Service Implementation Plan

Date: 2026-07-23

Design: `docs/superpowers/specs/2026-07-23-english-fallback-tokenizer-service-design.md`

## Steps

1. Add direct service tests and a facade/source integration guard without changing existing fallback behavior tests.
2. Run RED because `EnglishFallbackTokenizerService.php` does not exist.
3. Implement the exact production fallback tokenizer, conservative lemma rules, and irregular mapping in the new pure service.
4. Run direct behavior tests GREEN while the facade integration guard remains RED.
5. Give `TextBlockService` a private collaborator, keep `fallbackEnglishTokenize()` as a thin private adapter with the protected ECDICT callbacks, and keep `englishIrregularLemma()` as a thin doctor adapter.
6. Remove only the moved fallback implementation bodies and irregular table from `TextBlockService`; retain Python transport, doctor-only heuristics, ECDICT DB/cache, structural mapping, and all public facades.
7. Run focused fallback tests, combined protected PHP suites, frontend build, source guards, and diff checks.
8. Prepare an isolated testing-MySQL English chapter with an intentionally unavailable Python endpoint so the real fallback path creates the displayed Reader tokens.
9. Use the official OpenAI Browser connection to verify render, hover, selected-word lookup, stage classes, and zero overflow at true wide and narrow viewports.
10. Snapshot protected tables, clean the fixture twice, review the slice, update authority documents, and audit whether the remaining Phase 6 backend requirements are fully satisfied.

## Success

- Production fallback behavior has one pure owner.
- `TextBlockService` keeps its public facade, private reflection seam, protected ECDICT hooks, Python-first decision, structural mapping, and downstream pipeline.
- Existing and direct tests prove identical token shape, lemma behavior, exceptions, and override semantics.
- The fallback-prepared real chapter is accepted at both viewports with no protected write.

## Failure

- Any changed token, lemma, POS, sentence index, exception, regex behavior, ECDICT callback/query, Python-first behavior, import data, Reader payload, or visible UI.
- Any constructor/public contract, generic abstraction, dependency, external capability, backend write, or adjacent cleanup.
- Any protected write, browser regression, or unexplained test failure.
