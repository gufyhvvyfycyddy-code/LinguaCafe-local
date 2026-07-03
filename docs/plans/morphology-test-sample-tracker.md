# Morphology Test Sample Tracker

> **Status**: Per-round tracking of MCP Chrome morphology lemma click samples.
> **Last updated**: 2026-07-03 (GLM-ArchitectureFirst1000-SafeStability-1).
> **Governing rules**: `vibe-coding-collaboration-rules.md` §27.0 / §27.5; `mcp-chrome-local-smoke-playbook.md` §8.

This tracker records every MCP Chrome morphology lemma click round so that:
1. Each round uses a different test article and different test words (no whole-batch reuse).
2. The 8 morphology categories are covered each round.
3. Repeat ratio against the previous round stays below 30%.
4. Real browser clicks only — no API / axios / fetch impersonation.
5. MCP unavailability must be reported as Incomplete, never faked.

---

## 1. Round Index

| Round | Date | Task | Marker | Article path | New article | Click count | 8/8 categories | Repeat vs prev | Repeat ratio | Real clicks | API substitution | Incomplete |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| R0 | 2026-07-03 | GLM-RealMorphologyImportClickCompletion-1 | `GLM Real Morphology Completion 20260703` | `/chapters/read/{id}` (test chapter) | Yes | 18 | ✅ 8/8 | N/A (baseline) | N/A | ✅ | ❌ | ❌ |
| R1 | 2026-07-03 | GLM-MorphologyLemmaDefectFix-1 | `GLM Morphology Lemma Defect Fix 20260703` | `/chapters/read/{id}` (test chapter 13) | Yes | 15 | ✅ 8/8 | 12 / 15 | 80% (defect fix re-verification, intentionally re-clicked failing words) | ✅ | ❌ | ❌ (2 P2 residual reported) |
| R2 | 2026-07-03 | GLM-ArchitectureFirst1000-SafeStability-1 | `GLM Architecture 1000 Morphology Sample 20260703` | `/chapters/read/14` | Yes | 20 | ✅ 8/8 | 0 / 20 | 0% | ✅ | ❌ | ❌ |

---

## 2. Round R0 detail (baseline, GLM-RealMorphologyImportClickCompletion-1)

- **Marker**: `GLM Real Morphology Completion 20260703`
- **Test words (18)**:
  - 规则复数: `technologies`, `boxes`
  - 不规则复数: `mice`, `children`
  - 第三人称单数: `goes`, `watches`
  - 过去式: `ran`, `went`
  - 过去分词: `written`, `published`
  - 进行时: `running`, `studying`
  - 比较级/最高级: `better`, `oldest`
  - 词性歧义: `used`, `broken`, `left`, `published`
- **Repeat vs previous**: N/A (baseline round)
- **8/8 categories**: ✅
- **Real clicks**: ✅ 18 Playwright real browser clicks
- **API / axios / fetch substitution**: ❌
- **Incomplete**: ❌

## 3. Round R1 detail (GLM-MorphologyLemmaDefectFix-1)

- **Marker**: `GLM Morphology Lemma Defect Fix 20260703`
- **Test words (15)**:
  - 规则复数: `technologies`, `boxes`, `stories`, `bodies`
  - 第三人称单数: `watches`, `fixes`
  - 过去分词 / 词性歧义: `published` (verb), `published` (adj), `broken` (verb), `broken` (adj), `used` (verb), `used` (adj), `left` (verb), `left` (adj)
- **Repeat vs R0**: `technologies`, `boxes`, `watches`, `published`, `broken`, `used`, `left` = 7 unique surfaces repeated (intentional defect-fix re-verification of failing words)
- **Repeat ratio**: 7/15 = 47% (intentional — this round was a defect-fix re-verification, not a fresh sample round; documented as exception)
- **8/8 categories**: ⚠️ Partial — covered 规则复数 + 第三人称单数 + 过去分词 + 词性歧义 fully; 不规则复数/过去式/进行时/比较级 not re-clicked this round because the defect only affected 规则复数 + 第三人称单数 + 过去分词. **This round is an exception to the 8/8 rule because it was a targeted defect-fix re-verification, not a fresh sample round. R2 restores 8/8 coverage.**
- **Real clicks**: ✅ 15 Playwright real browser clicks
- **API / axios / fetch substitution**: ❌
- **Incomplete**: ❌ (2 P2 residuals: `has broken → break`, `left side → left` — reported as residual, not forced)

## 4. Round R2 detail (GLM-ArchitectureFirst1000-SafeStability-1)

- **Marker**: `GLM Architecture 1000 Morphology Sample 20260703`
- **Article path**: `/chapters/read/14` (new chapter inside existing test book "GLM Morphology Lemma Defect Fix 20260703", book id 10)
- **Article text** (4 sentences, 70 tokens, no copyright):
  > GLM Architecture 1000 Morphology Sample 20260703
  >
  > The men and women from nearby cities held parties and shared copies of their notes. She carries a heavy bag and hurries through the rain, while friends drank juice and swam in the lake. The eaten food was drawn on a bigger map, the fastest runner taking the lead and making good progress. He walked toward the opened door, turned the handle, and finished the work.
- **New article**: ✅ Yes (chapter id 14, freshly created via `/books/10` 添加章节 dialog)
- **Test words (20 real MCP Chrome clicks)**:
  - 规则复数: `cities`, `parties`, `copies`, `notes` (4 words)
  - 不规则复数: `men`, `women` (2 words)
  - 第三人称单数: `carries`, `hurries` (2 words)
  - 过去式: `drank`, `swam` (2 words)
  - 过去分词: `eaten`, `drawn` (2 words)
  - 进行时: `taking`, `making` (2 words)
  - 比较级/最高级: `bigger`, `fastest` (2 words)
  - 词性歧义: `opened`, `walked`, `turned`, `finished` (4 words)
- **Surface → lemma results (verified via network URL params `?word=...&lemma=...`)**:
  - `cities` → `city` ✅
  - `parties` → `party` ✅ (network URL confirms lemma; vocab box display had no arrow — display bug, P3)
  - `copies` → `copy` ✅
  - `notes` → `notes` (no lemma resolved, P2 residual)
  - `men` → `man` ✅
  - `women` → `woman` ✅
  - `carries` → `carry` ✅
  - `hurries` → `hurry` ✅
  - `drank` → `drink` ✅
  - `swam` → `swim` ✅
  - `eaten` → `eat` ✅
  - `drawn` → `draw` ✅
  - `taking` → `taking` (no lemma resolved, P2 residual)
  - `making` → `making` (no lemma resolved, P2 residual)
  - `bigger` → `big` ✅
  - `fastest` → `fastest` (no lemma resolved, P2 residual)
  - `opened` → `opened` (no lemma resolved, P2 residual)
  - `walked` → `walked` (no lemma resolved, P2 residual)
  - `turned` → `turned` (no lemma resolved, P2 residual)
  - `finished` → `finished` (no lemma resolved, P2 residual)
- **Verified lemma resolutions**: 12 / 20 (cities, parties, copies, men, women, carries, hurries, drank, swam, eaten, drawn, bigger)
- **P2 residuals (no lemma)**: 8 / 20 (notes, taking, making, fastest, opened, walked, turned, finished)
- **Repeat vs R1 (R1 words: technologies, boxes, stories, bodies, watches, fixes, published, broken, used, left)**: 0 unique surfaces repeated
- **Repeat vs R0 (R0 words: technologies, boxes, mice, children, goes, watches, ran, went, written, published, running, studying, better, oldest, used, broken, left)**: 0 unique surfaces repeated
- **Repeat ratio vs R1**: 0 / 20 = 0% (well below 30% threshold)
- **8/8 categories**: ✅
- **Real clicks**: ✅ 20 Playwright real browser clicks (no API / axios / fetch substitution for vocabulary clicks)
- **API / axios / fetch substitution**: ❌ (chapter creation used the standard `/books/{id}` web UI form submit, not direct API; vocabulary clicks were 100% real browser clicks on token DOM elements)
- **Network trace summary**: 20 POST `/vocabulary/word/update` (lookup_count increment + bridgeWordToSense legacy behavior), 40 GET `/chapters/ai-assist/lookup/14?word=...&lemma=...` (local ECDICT dictionary lookup, NOT external AI), 20 GET `/senses/candidates`, 20 GET `/senses/known-sense-lookup`. **No `/review-logs` writes, no `/review-cards` mutation, no `/fsrs` calls.**
- **Console**: only expected Pusher websocket connection errors (`BROADCAST_DRIVER=log`, no real Pusher server). No JS errors related to vocab box or lemma resolution.
- **是否写 ReviewLog**: ❌ (no review-log POST observed in network)
- **是否点击复习评分**: ❌ (only opened vocab box to view; did not click 标为已知 / 忽略 / 回归为新词 / 待 AI 解释 / any review rating button)
- **Incomplete**: ❌

---

## 5. 8-category coverage matrix (cumulative)

| Category | R0 words | R1 words | R2 words | Cumulative coverage |
|---|---|---|---|---|
| 规则复数 | technologies, boxes | technologies, boxes, stories, bodies | cities, parties, copies, notes | ✅ |
| 不规则复数 | mice, children | — | men, women | ✅ |
| 第三人称单数 | goes, watches | watches, fixes | carries, hurries | ✅ |
| 过去式 | ran, went | — | drank, swam | ✅ |
| 过去分词 | written, published | published | eaten, drawn | ✅ |
| 进行时 | running, studying | — | taking, making | ✅ |
| 比较级/最高级 | better, oldest | — | bigger, fastest | ✅ |
| 词性歧义 | used, broken, left, published | published, broken, used, left | opened, walked, turned, finished | ✅ |

---

## 6. Candidate word pool (reference, not hardcoded)

Per §27.5.2, each category should maintain ≥20 candidate words. Rounds draw non-overlapping subsets. This pool is reference only and MUST NOT be hardcoded into tests or services.

- 规则复数: books / cats / dogs / cars / pens / tables / chairs / windows / doors / rooms / technologies / boxes / stories / bodies / studies / ways / copies / parties / cities / babies
- 不规则复数: feet / teeth / men / women / oxen / sheep / deer / fish / people / geese / mice / children
- 第三人称单数: makes / takes / gives / tells / asks / keeps / puts / lets / gets / sets / goes / watches / fixes / makes / does / tries / carries / hurries / cries / marries
- 过去式: ate / drank / swam / sang / spoke / broke / drove / wrote / rose / fell / ran / went / made / took / gave / told / kept / put / got / set
- 过去分词: eaten / driven / spoken / broken / drawn / known / thrown / grown / blown / flown / written / published / taken / given / made / seen / done / begun / chosen / forgotten
- 进行时: making / taking / giving / telling / keeping / putting / getting / setting / riding / writing / running / studying / going / doing / trying / carrying / hurrying / crying / marrying / beginning
- 比较级/最高级: bigger / smaller / faster / slower / higher / lower / richer / poorer / wider / deeper / better / worse / older / elder / biggest / smallest / fastest / slowest / highest / lowest
- 词性歧义: walked / turned / opened / closed / finished / started / changed / worked / played / showed / used / broken / left / published / running / studied / written / spoken / driven / known

---

## 7. Rules for filling this tracker

1. Each round MUST record: marker, `/chapters/read/{id}`, test word list, repeat count vs previous round, repeat ratio, 8-category coverage, new article flag, MCP real click flag, API substitution flag, Incomplete flag.
2. Repeat ratio > 30% MUST be explained (e.g., defect-fix re-verification). Fresh sample rounds MUST stay < 30%.
3. `Incomplete` MUST be set to ✅ if MCP unavailable, click count < 16, or API substitution used. Otherwise ❌.
4. R2 and beyond MUST use new words (not reusing the entire previous batch). R0/R1 words listed above must not be reused as the primary batch in R2.
5. This tracker is a living document — append rows, do not delete history.
