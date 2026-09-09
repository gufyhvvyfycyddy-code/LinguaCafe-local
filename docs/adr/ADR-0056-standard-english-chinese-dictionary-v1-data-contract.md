# ADR-0056 — Standard English→Chinese Dictionary v1 Data Contract

Status: Proposed<br>
Date: 2026-09-09<br>
Drives: Source #70 (build bundled standard EN→ZH dictionary), #69 (product direction), #72 (later legacy retirement)<br>
Open gates this ADR depends on:
- Product-Launch #13 (content rights) — which source data may be **shipped** (see §6).
- Architecture-review #34 — storage-structure choice (§4) is a subsystem-architecture decision.

## 1. Context

LinguaCafe must work out of the box: an ordinary user installs, opens an
English text, clicks a word or phrase, and immediately gets a usable Chinese
explanation — without learning about dictionary files, formats, or admin
import (#69, #70).

Current state (subsystem map, master `8444e15`):

- `dictionaries` is a **registry** table (one row per dictionary:
  `name`, `database_table_name`, `type`, `source_language`, `target_language`,
  `api_host`, `color`, `enabled`). It holds no entries.
- Every non-JMDict dictionary uses one **generic, thin** word table created at
  runtime by `DictionaryImportService::createDictionaryTable()`:
  `id`, `word VARCHAR(256) utf8mb4_bin (indexed)`,
  `definitions VARCHAR(2048) utf8mb4_bin`, `timestamps`. Multiple senses are
  flattened into one `;`-joined string; **there is no POS, phonetic,
  sense-ordering, frequency, inflection, or phrase structure**, and readings
  are discarded on import.
- **No dictionary is bundled or seeded.** A fresh install has zero usable
  dictionaries; English→Chinese today requires manual admin import or an
  online API dictionary. There is no offline bundled EN→ZH path.
- Lookup (`DictionaryService::searchDefinitionsForHoverVocabulary`) is
  **read-only reference**: it never writes `WordSense`/`ReviewCard`/`ReviewLog`
  and never touches FSRS. Conversion to learning data flows only through the
  canonical owners (`WordSense`/`ReviewCard`).

Candidate data sources and their **redistribution** rights (see #70 comment /
#13): Princeton **WordNet** (permissive, commercial OK; English only — lemma /
sense / POS / morphology); **Wiktextract/Kaikki** (CC-BY-SA 3.0 + GFDL;
English headwords with Chinese translations, senses, POS, IPA, inflections,
phrases); **ECDICT** (richest EN→ZH coverage but mixed data provenance →
**benchmark only** until a rights review).

## 2. Goals / non-goals

Goals:
- A versioned, reproducible, **read-only** bundled EN→ZH reference dictionary,
  available by default after install, consumed by the existing reader lookup.
- Words and multi-word expressions treated as **separate lexical classes**
  (#70.D): their resolution mechanics differ (single-token normalize/lemma vs
  longest-match over a token sequence).
- Quality the current thin table cannot express: ordered senses (most common
  first), POS, phonetic, and phrase/phrasal-verb coverage.
- Deterministic build artifact with a source/license/version manifest and
  atomic update + rollback.

Non-goals:
- AI as a source of truth (AI may only assist QA/triage, never author shipped
  entries).
- Changing learning-data semantics. Dictionary content never auto-creates
  `WordSense`/`ReviewCard`, never writes `ReviewLog`, never mutates FSRS, and
  never changes the frozen true-new-card initial state (#64).

## 3. Proposed v1 entry contract (source-agnostic)

Word entry (one per headword + POS sense-group):

| field | meaning |
|---|---|
| `headword` | canonical surface form as displayed (e.g. `run`) |
| `normalized` | lookup key: lowercased, NFC, diacritics folded |
| `lemma` | base form (e.g. `ran`→`run`); equals headword for base entries |
| `pos` | canonical POS (LinguaCafe POS set; see ADR-0022 boundary) |
| `phonetic` | IPA (optional) |
| `frequency_rank` | corpus rank for sense/entry ordering (nullable) |
| `senses[]` | ordered list, most-useful first: `{ rank, gloss_zh, gloss_en?, register/tags[] }` |
| `provenance` | `{ source, source_version, license }` per entry/batch |

Inflection map (separate, supports lemma fallback): `surface_normalized → lemma`
(from WordNet morphy + source `forms`/`exchange`).

Phrase entry (separate lexical class):

| field | meaning |
|---|---|
| `phrase` | surface phrase (e.g. `take off`) |
| `normalized_tokens` | ordered normalized token list for longest-match |
| `phrase_type` | `phrasal_verb` \| `collocation` \| `idiom` \| `mwe` |
| `senses[]` | same shape as word senses |
| `provenance` | as above |

Lookup resolution order (consumed by the reader, no new truth store):
1. phrase longest-match over the clicked token window (phrase lexicon);
2. exact `normalized` (word lexicon);
3. `lemma` fallback via the inflection map;
4. existing behavior (other enabled dictionaries / API dictionaries) unchanged.

## 4. Storage structure — DECISION DEFERRED to prototype + Architecture #34

Two options; pick with evidence, do not duplicate the same data in two stores:

- **(A) Reuse the generic thin table** (`word` + `;`-joined `definitions`).
  Pro: zero lookup-layer change. Con: cannot express ordered senses / POS /
  phonetic / phrase class → fails #70 quality gates. Rejected for quality.
- **(B) Dedicated bundled tables** (e.g. `std_dict_en_zh_words`,
  `std_dict_en_zh_phrases`, `std_dict_en_zh_forms`) with proper columns and
  indexes, registered by **one** `dictionaries` row (`type='bundled'`,
  `source_language='english'`, `target_language='chinese'`), rendered by a
  single added branch in the lookup owner (`DictionaryService`) — analogous to
  the existing JMDict special-case but cleaner.
  Pro: expresses quality fields; indexable; phrase class first-class.
  Con: adds lookup-layer surface.

Recommendation: **(B)**, validated by a prototype against real data before
freezing column types/sizes. This is a dictionary-subsystem architecture
decision and is registered for Architecture-review #34.

Performance note (must be addressed when (B) lands): add the missing
`dictionaries (source_language, enabled)` index; avoid the per-lookup N+1 over
enabled dictionaries and the `ORDER BY LENGTH(word)` filesort observed in the
current generic path.

## 5. Versioning, manifest, update/rollback

- `LinguaCafe Standard English Dictionary v1` is a versioned artifact with:
  `dictionary_version`, per-source `{source, source_version, dump_date,
  license}` manifest, build checksum, entry metrics, `schema_version`.
- Update is **atomic**: build/import the new version into staging, verify, then
  switch (reusing the existing atomic `RENAME TABLE` publish pattern); a failed
  update must never leave users unable to look up words. Rollback restores the
  previous version.

## 6. Rights (gate — Product-Launch #13)

- Ship the **English backbone** from WordNet (permissive) and **Chinese senses**
  from Wiktextract (CC-BY-SA 3.0 + GFDL, with attribution + ShareAlike recorded
  in the license manifest, scoped to the dictionary dataset).
- ECDICT is used **only** as a coverage/quality benchmark; it does not enter the
  shipped artifact unless its mixed-provenance data passes a separate rights
  review.
- The bundled artifact ships a source manifest + license manifest + attribution.
  No source with unclear rights enters the shipped artifact.

## 7. Consequences

- Enables out-of-box EN→ZH lookup with quality the current table cannot express.
- Adds a bundled-dictionary lookup branch (option B) but keeps a single lookup
  owner and the read-only reference boundary intact.
- The contract is deliberately marked **Proposed**: column types, exact POS
  mapping, and storage option (A vs B) are finalized only after a Stage-3
  prototype against real WordNet/Wiktextract samples, and after the #13 rights
  decision. This ADR records direction, not a frozen implementation.
