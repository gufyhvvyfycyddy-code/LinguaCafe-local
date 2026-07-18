# Reader Hover Lookup Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the existing Reader hover lookup mode and search-term decision into a pure policy without changing UI, requests, settings, data, timing or presentation behavior.

**Architecture:** `TextBlockGroup.vue` remains the mouse/touch, timer, HTTP, Vuex and DOM adapter. A new pure `HoverVocabularyLookupPolicy.js` returns `closed`, `local-only`, or `search` plus the exact current term, including the current empty-array and normalized-empty behavior.

**Tech Stack:** Vue 2, Vuex, Laravel Mix, native ES modules, Node `node:test`, PHPUnit, Playwright CLI browser acceptance.

## Global Constraints

- Do not add or move the existing `vocabularyHoverBoxSearch` switch.
- Do not change its `vocabulary-hover-box-search` localStorage key or default.
- Do not change timers, Axios endpoints/payloads, Vuex shape, DOM positioning or stale-response behavior.
- Do not touch `TextReader.vue`, `TextReaderSettings.vue`, Vuex, vocabulary presentation components, PHP, database, AI, source context, WordSense, ReviewCard, ReviewLog or FSRS code.
- Only `HoverVocabularyLookupPolicy.js`, `TextBlockGroup.vue`, `HoverVocabularyLookupPolicy.test.mjs` and milestone documentation are allowed.
- The policy must not mutate `hoveredWords` or token objects.
- `hoveredWords === null` closes; an empty array remains local-only or empty-term search according to the existing search switch.
- A non-empty raw lemma never falls back after normalization, even if normalization returns an empty string.
- Phrase spacing uses `spaceAfter` only between tokens and never appends a trailing space.

---

### Task 1: Pure Hover Lookup Policy

**Files:**

- Create: `tests/js/HoverVocabularyLookupPolicy.test.mjs`
- Create: `resources/js/services/HoverVocabularyLookupPolicy.js`

**Interfaces:**

- Consumes: trusted internal `{ hoverBoxEnabled, searchEnabled, plainTextMode, hoveredWords, normalizeLemma }`.
- Produces: `resolveHoverVocabularyLookup(input): { mode: 'closed' | 'local-only' | 'search', term: string }`.

- [ ] **Step 1: Write the failing policy test**

Create `tests/js/HoverVocabularyLookupPolicy.test.mjs`:

```js
import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveHoverVocabularyLookup } from '../../resources/js/services/HoverVocabularyLookupPolicy.js';

const normalizeLemma = (term) => term.toLowerCase().replace(/^the\s+/, '');

test('closes only for disabled hover, plain text, or null words', () => {
    for (const input of [
        { hoverBoxEnabled: false, searchEnabled: true, plainTextMode: false, hoveredWords: [] },
        { hoverBoxEnabled: true, searchEnabled: true, plainTextMode: true, hoveredWords: [] },
        { hoverBoxEnabled: true, searchEnabled: true, plainTextMode: false, hoveredWords: null },
    ]) {
        assert.deepEqual(resolveHoverVocabularyLookup({ ...input, normalizeLemma }), { mode: 'closed', term: '' });
    }
});

test('disabled search preserves local-only behavior for words and empty arrays', () => {
    for (const hoveredWords of [[], [{ word: 'Surface', lemma: 'Lemma', spaceAfter: false }]]) {
        assert.deepEqual(resolveHoverVocabularyLookup({
            hoverBoxEnabled: true,
            searchEnabled: false,
            plainTextMode: false,
            hoveredWords,
            normalizeLemma,
        }), { mode: 'local-only', term: '' });
    }
});

test('one word prefers a non-empty raw lemma', () => {
    assert.deepEqual(resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords: [{ word: 'SURFACE', lemma: 'The Lemma', spaceAfter: false }],
        normalizeLemma,
    }), { mode: 'search', term: 'lemma' });
});

test('one word falls back only when the raw lemma is empty', () => {
    const base = { hoverBoxEnabled: true, searchEnabled: true, plainTextMode: false, normalizeLemma };
    assert.deepEqual(resolveHoverVocabularyLookup({
        ...base,
        hoveredWords: [{ word: 'Surface', lemma: '', spaceAfter: false }],
    }), { mode: 'search', term: 'Surface' });
    assert.deepEqual(resolveHoverVocabularyLookup({
        ...base,
        hoveredWords: [{ word: 'Surface', lemma: 'The ', spaceAfter: false }],
    }), { mode: 'search', term: '' });
});

test('phrases honor inter-token spaces without a trailing space', () => {
    assert.deepEqual(resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords: [
            { word: 'look', lemma: '', spaceAfter: true },
            { word: 'up', lemma: '', spaceAfter: true },
        ],
        normalizeLemma,
    }), { mode: 'search', term: 'look up' });
});

test('an empty array remains an empty-term search', () => {
    assert.deepEqual(resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords: [],
        normalizeLemma,
    }), { mode: 'search', term: '' });
});

test('does not mutate frozen hover inputs', () => {
    const word = Object.freeze({ word: 'Surface', lemma: 'Lemma', spaceAfter: true });
    const hoveredWords = Object.freeze([word]);
    assert.doesNotThrow(() => resolveHoverVocabularyLookup({
        hoverBoxEnabled: true,
        searchEnabled: true,
        plainTextMode: false,
        hoveredWords,
        normalizeLemma,
    }));
    assert.deepEqual(hoveredWords, [word]);
});
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
node tests/js/HoverVocabularyLookupPolicy.test.mjs
```

Expected: failure with `ERR_MODULE_NOT_FOUND` for `HoverVocabularyLookupPolicy.js`.

- [ ] **Step 3: Add the minimal pure implementation**

Create `resources/js/services/HoverVocabularyLookupPolicy.js`:

```js
const closedDecision = () => ({ mode: 'closed', term: '' });
const localOnlyDecision = () => ({ mode: 'local-only', term: '' });

export function resolveHoverVocabularyLookup({
    hoverBoxEnabled,
    searchEnabled,
    plainTextMode,
    hoveredWords,
    normalizeLemma,
}) {
    if (!hoverBoxEnabled || plainTextMode || hoveredWords === null) {
        return closedDecision();
    }

    if (!searchEnabled) {
        return localOnlyDecision();
    }

    let term = '';
    if (hoveredWords.length === 1) {
        const word = hoveredWords[0];
        term = word.lemma.length ? normalizeLemma(word.lemma) : word.word;
    } else {
        term = hoveredWords.map((word, index) => (
            word.word + (word.spaceAfter && index < hoveredWords.length - 1 ? ' ' : '')
        )).join('');
    }

    return { mode: 'search', term };
}
```

- [ ] **Step 4: Run the focused test and verify GREEN**

Run:

```powershell
node tests/js/HoverVocabularyLookupPolicy.test.mjs
```

Expected: 7 tests pass, 0 fail.

- [ ] **Step 5: Commit the pure policy**

```powershell
git add -- resources/js/services/HoverVocabularyLookupPolicy.js tests/js/HoverVocabularyLookupPolicy.test.mjs
git diff --cached --check
git commit -m "refactor: add reader hover lookup policy"
```

### Task 2: TextBlockGroup Adapter

**Files:**

- Modify: `resources/js/components/Text/TextBlockGroup.vue`
- Modify: `tests/js/HoverVocabularyLookupPolicy.test.mjs`

**Interfaces:**

- Consumes: `resolveHoverVocabularyLookup()` from Task 1.
- Produces: the existing hover UI and request behavior through the new policy decision.

- [ ] **Step 1: Add a failing source-integration guard**

Append to `tests/js/HoverVocabularyLookupPolicy.test.mjs`:

```js
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const textBlockSource = fs.readFileSync(path.join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');

test('TextBlockGroup delegates hover lookup decisions without moving request ownership', () => {
    assert.match(textBlockSource, /import\s*\{\s*resolveHoverVocabularyLookup\s*\}/);
    assert.match(textBlockSource, /const lookupDecision = resolveHoverVocabularyLookup\(/);
    assert.match(textBlockSource, /lookupDecision\.mode === 'closed'/);
    assert.match(textBlockSource, /lookupDecision\.mode === 'local-only'/);
    assert.match(textBlockSource, /makeHoverVocabularyBoxSearchRequest\(lookupDecision\.term\)/);
    assert.match(textBlockSource, /axios\.post\('\/dictionaries\/search-for-hover-vocabulary'/);
});
```

- [ ] **Step 2: Run the guard and verify RED**

Run:

```powershell
node tests/js/HoverVocabularyLookupPolicy.test.mjs
```

Expected: the seven policy tests pass; the integration guard fails because `TextBlockGroup.vue` has not imported or called the policy.

- [ ] **Step 3: Adapt `TextBlockGroup.vue` minimally**

Add the import beside the existing Reader helpers:

```js
import { resolveHoverVocabularyLookup } from './../../services/HoverVocabularyLookupPolicy';
```

At the start of `updateHoverVocabularyBox(data)`, resolve once:

```js
const lookupDecision = resolveHoverVocabularyLookup({
    hoverBoxEnabled: this.$props.vocabularyHoverBox,
    searchEnabled: this.$props.vocabularyHoverBoxSearch,
    plainTextMode: this.$props.plainTextMode,
    hoveredWords: data.hoveredWords,
    normalizeLemma: (lemma) => this.trimSearchTerm(lemma),
});

if (lookupDecision.mode === 'closed') {
    this.closeHoverBox();
    return;
}
```

Keep the existing Vuex projections and timeout clearing in their current order. Replace only the current `!vocabularyHoverBoxSearch` branch condition:

```js
if (lookupDecision.mode === 'local-only') {
```

Inside the existing search timeout, delete the inline single-word/phrase `term` construction and call:

```js
this.makeHoverVocabularyBoxSearchRequest(lookupDecision.term);
```

Do not edit `makeHoverVocabularyBoxSearchRequest()`, `clearHoverVocabularyBoxTimeout()`, positioning, endpoints, payloads or response handlers.

- [ ] **Step 4: Run focused and adjacent Node tests**

```powershell
node tests/js/HoverVocabularyLookupPolicy.test.mjs
node tests/js/ReaderWorkspaceSizingService.test.mjs
node tests/js/EncounteredWordStageAuthorityGuard.test.mjs
```

Expected: all pass.

- [ ] **Step 5: Build the frontend**

```powershell
npm run development
```

Expected: compiled successfully; existing Sass deprecation warnings are allowed, new compile errors are not.

- [ ] **Step 6: Commit the adapter**

```powershell
git add -- resources/js/components/Text/TextBlockGroup.vue tests/js/HoverVocabularyLookupPolicy.test.mjs
git diff --cached --check
git commit -m "refactor: delegate reader hover lookup policy"
```

### Task 3: Protected Regression, Smoke and Browser Closure

**Files:**

- Create: `docs/testing/reader-hover-lookup-policy-browser-acceptance-2026-07-18.md`
- Modify after acceptance: `docs/plans/anki-aligned-product-and-architecture-roadmap.md`
- Modify after acceptance: `docs/plans/current-working-handoff.md`
- Modify after acceptance: `docs/plans/linguacafe-master-plan.md`
- Modify after acceptance: `docs/DOCUMENTATION_INDEX.md`

**Interfaces:**

- Consumes: the completed policy and adapter.
- Produces: repeatable Phase 6A closure evidence and an explicit next Reader slice.

- [ ] **Step 1: Verify the testing database before Feature tests**

```powershell
php artisan test --filter=TestingDatabaseHealthTest
php artisan test --filter=TestingDatabaseHealthConfigTest
```

Expected: both pass against the configured testing MySQL database.

- [ ] **Step 2: Run protected Reader regression**

```powershell
php artisan test --filter=ReaderFsrsHighlightTest
php artisan test --filter=TextBlockPhraseIndexingTest
```

Expected: all pass; no Reader payload or FSRS behavior change.

- [ ] **Step 3: Run the text Reader smoke guard**

Use the smoke guard's documented default English chapter fixture (chapter 5):

```powershell
python tools\smoke\text_reader_smoke_guard.py --base-url http://localhost:8000 --chapter-id 5
```

Expected: reader load, token render, click lookup and protected smoke assertions pass. If the repository's current smoke playbook requires a different launcher or authentication input, follow `docs/testing/text-reader-smoke-guard.md` without changing production code.

- [ ] **Step 4: Perform authenticated browser acceptance**

At 1920×1080 and 900×900:

1. Open an English chapter and confirm tokens, colors and click lookup.
2. With hover dictionary search enabled, hover a word and confirm one delayed `/dictionaries/search-for-hover-vocabulary` request uses the expected lemma/surface term.
3. Move to a second word and confirm the old local-dictionary response does not overwrite it.
4. Disable “悬浮词汇框词典搜索”, hover a word, confirm local hover content remains visible and neither hover dictionary endpoint is requested.
5. Re-enable the setting and confirm persistence after reload.
6. Confirm no horizontal overflow and that completion remains operable.
7. Compare testing-DB counts/checksums before and after; hovering must create or modify no WordSense, ReviewCard, ReviewLog or FSRS row.

- [ ] **Step 5: Write and validate closure documents**

Record exact commands, counts, viewport results, Network evidence, Console evidence and zero-write evidence in `docs/testing/reader-hover-lookup-policy-browser-acceptance-2026-07-18.md`. Update authority documents only after all acceptance steps pass. Mark only Phase 6A closed; name the next one-responsibility Reader slice rather than claiming all Phase 6 complete.

Run:

```powershell
git diff --check
rg -n "Phase 6|Reader|hover lookup|Current Phase" docs/plans/anki-aligned-product-and-architecture-roadmap.md docs/plans/current-working-handoff.md docs/plans/linguacafe-master-plan.md docs/DOCUMENTATION_INDEX.md
```

Expected: no whitespace errors, broken authority links or contradictory current-phase statements.

- [ ] **Step 6: Commit exact closure files**

```powershell
git add -- docs/testing/reader-hover-lookup-policy-browser-acceptance-2026-07-18.md docs/plans/anki-aligned-product-and-architecture-roadmap.md docs/plans/current-working-handoff.md docs/plans/linguacafe-master-plan.md docs/DOCUMENTATION_INDEX.md
git diff --cached --check
git commit -m "docs: close reader hover lookup slice"
```
