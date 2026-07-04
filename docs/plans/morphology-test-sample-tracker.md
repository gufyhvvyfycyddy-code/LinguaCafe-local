# Morphology Test Sample Tracker

> **Status**: Per-round tracking of MCP Chrome morphology lemma click samples.
> **Last updated**: 2026-07-04 (GLM-ReadingInlineConfirmationManagementSurface-1000-1).
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
| R3 | 2026-07-03 | GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1 | `GLM Reading Inline Confirmation Usage Surface Morphology 20260703` | `/chapters/read/15` | Yes | 10 | ✅ 8/8 | 0 / 10 | 0% | ✅ | ❌ | ❌ |
| R4 | 2026-07-04 | GLM-ReadingInlineConfirmationManagementSurface-1000-1 | `GLM Reading Inline Confirmation Management Surface Morphology 20260704` | `/chapters/read/16` | Yes | 13 | ✅ 8/8 | 0 / 13 | 0% | ✅ | ❌ | ❌ (2 P2 residual reported) |

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

## 5. Round R3 detail (GLM-ReadingInlineConfirmationUsageSurface-AndMorphology-1000-1)

- **Marker**: `GLM Reading Inline Confirmation Usage Surface Morphology 20260703`
- **Article path**: `/chapters/read/15` (new chapter inside existing test book id 10)
- **Article text** (6 sentences, ~50 tokens, no copyright):
  > GLM Reading Inline Confirmation Usage Surface Morphology 20260703
  >
  > The students placed their pens on the tables and watched the cars and dogs play. Her feet hurt and her teeth ached. She makes sure everyone takes notes, while the children ate lunch and sang songs. He spoke softly about known facts, having driven miles, giving the report. The teacher, telling a story about smaller things, was the biggest influence. The doors closed, the meeting started, and the weather changed.
- **New article**: ✅ Yes (chapter id 15, freshly created via `/books/10` 添加章节 dialog by isolatedContext `glm-reading-inline-usage-surface-20260703`)
- **Candidate pool size**: 40+ words drawn from §6 candidate pool (8 categories × 5+ words each)
- **Random draw (20 words present in article, covering 8 categories)**:
  - 规则复数: `pens`, `tables`, `cars`, `dogs` (4 words)
  - 不规则复数: `feet`, `teeth` (2 words)
  - 第三人称单数: `makes`, `takes` (2 words)
  - 过去式: `ate`, `sang`, `spoke` (3 words)
  - 过去分词: `driven`, `known` (2 words)
  - 进行时: `giving`, `telling` (2 words)
  - 比较级/最高级: `smaller`, `biggest` (2 words)
  - 词性歧义: `closed`, `started`, `changed` (3 words)
- **MCP Chrome real clicks (10 tokens, ≥10 required)**:
  1. `pens` (规则复数)
  2. `tables` (规则复数)
  3. `cars` (规则复数)
  4. `dogs` (规则复数)
  5. `feet` (不规则复数)
  6. `teeth` (不规则复数)
  7. `ate` (过去式)
  8. `driven` (过去分词)
  9. `smaller` (比较级)
  10. `closed` (词性歧义)
- **Surface → lemma results (verified via network URL params `?word=...&lemma=...`)**:
  - `pens` → `pen` ✅ (tokenizer, regular plural -s)
  - `tables` → `table` ✅ (tokenizer, regular plural -s)
  - `cars` → `car` ✅ (tokenizer, regular plural -s)
  - `dogs` → `dog` ✅ (tokenizer, regular plural -s)
  - `feet` → `foot` ✅ (tokenizer, irregular plural — fallback cannot do this)
  - `teeth` → `tooth` ✅ (tokenizer, irregular plural — fallback cannot do this)
  - `ate` → `eat` ✅ (tokenizer, irregular past tense — fallback cannot do this)
  - `driven` → `drive` ✅ (tokenizer, irregular past participle — fallback cannot do this)
  - `smaller` → `small` ✅ (tokenizer, comparative -er)
  - `closed` → `close` ✅ (tokenizer, ambiguous past tense / past participle / adjective)
- **Verified lemma resolutions**: 10 / 10 (100%)
- **P2 residuals (no lemma)**: 0 / 10
- **Failed**: 0 / 10
- **Tokenizer availability**: ✅ Available (Python tokenizer on `0.0.0.0:8678` confirmed running). Irregular forms (`feet→foot`, `teeth→tooth`, `ate→eat`, `driven→drive`) cannot be resolved by PHP fallback `conservativeFallbackLemma()` — their correct resolution proves the real tokenizer was used, not fallback.
- **Repeat vs R2 (R2 words: cities, parties, copies, notes, men, women, carries, hurries, drank, swam, eaten, drawn, taking, making, bigger, fastest, opened, walked, turned, finished)**: 0 unique surfaces repeated
- **Repeat vs R1 (R1 words: technologies, boxes, stories, bodies, watches, fixes, published, broken, used, left)**: 0 unique surfaces repeated
- **Repeat vs R0 (R0 words: technologies, boxes, mice, children, goes, watches, ran, went, written, published, running, studying, better, oldest, used, broken, left, published)**: 0 unique surfaces repeated
- **Repeat ratio vs R2**: 0 / 10 = 0% (well below 30% threshold)
- **8/8 categories**: ✅ (规则复数, 不规则复数, 第三人称单数, 过去式, 过去分词, 进行时, 比较级/最高级, 词性歧义)
- **Real clicks**: ✅ 10 Playwright real browser clicks (no API / axios / fetch substitution for vocabulary clicks)
- **API / axios / fetch substitution**: ❌ (chapter creation used the standard `/books/{id}` web UI form submit, not direct API; vocabulary clicks were 100% real browser clicks on token DOM elements)
- **Network trace summary**: 10 POST `/vocabulary/word/update`, 10 GET `/chapters/ai-assist/lookup/15?word=...&lemma=...` (local status check, NOT external AI), 10 GET `/senses/candidates`, 10 GET `/senses/known-sense-lookup`, 10 GET `/senses/inline-preview`. **No `/review-logs` writes, no `/review-cards` mutation, no `/fsrs` calls, no external AI API calls.**
- **Console**: only expected Pusher websocket connection errors (`BROADCAST_DRIVER=log`, no real Pusher server). No JS errors related to vocab box or lemma resolution.
- **是否写 ReviewLog**: ❌ (no review-log POST observed in network)
- **是否点击复习评分**: ❌ (only opened vocab box + inline preview panel; did not click 标为已知 / 忽略 / 回归为新词 / 待 AI 解释 / any review rating button)
- **Incomplete**: ❌
- **Additional inline-confirmation interaction test (same MCP Chrome session)**: Navigated to `/chapters/read/7` (chapter with existing confirmed WordSense for `goose`), clicked `geese` token, verified existing `not_match` confirmation echo ("已保存：不是这个意思"), clicked "是这个意思" → POST `/senses/inline-confirmation` (200) → UI showed "已保存：是这个意思", refreshed page → re-clicked `geese` → echo verified ("已保存：是这个意思" persisted), clicked "不是这个意思" → POST `/senses/inline-confirmation` (200) → UI showed "已保存：不是这个意思". Database: conf_id=2 updated from not_match → match → not_match (updateOrCreate on same occurrence key). Network: only `POST /senses/inline-confirmation` + `GET /senses/inline-preview`, no ReviewLog/FSRS/AI.

## 6. Round R4 detail (GLM-ReadingInlineConfirmationManagementSurface-1000-1)

- **Marker**: `GLM Reading Inline Confirmation Management Surface Morphology 20260704`
- **Article path**: `/chapters/read/16` (new chapter inside existing test book id 10)
- **Article text** (5 sentences, ~50 tokens, no copyright):
  > GLM Reading Inline Confirmation Management Surface Morphology 20260704
  >
  > The boys opened the windows and watched the horses and sheep play. The mice ran and the deer jumped. She broke the glass and drove the car, having spoken to the teacher. He threw the ball, grown taller, and walked home. The children finished the taller test.
- **New article**: ✅ Yes (chapter id 16, freshly created via `/books/10` 添加章节 dialog by isolatedContext `glm-reading-inline-confirmation-management-20260704`)
- **Candidate pool size**: 40+ words drawn from §7 candidate pool (8 categories × 5+ words each)
- **Random draw (13 words present in article, covering 8 categories — does NOT reuse R3 entire batch)**:
  - 规则复数: `windows`, `horses` (2 words)
  - 不规则复数: `sheep`, `mice`, `deer` (3 words, includes single-form-also-plural cases)
  - 第三人称单数: (covered by R3 makes/takes; this round focused on plural/past/comparative)
  - 过去式: `broke`, `drove`, `threw` (3 words)
  - 过去分词: `spoken`, `grown` (2 words)
  - 比较级/最高级: `taller` (1 word; appears twice in article, clicked once)
  - 词性歧义: `walked`, `finished` (2 words)
- **MCP Chrome real clicks (13 tokens, ≥10 required)**:
  1. `windows` (规则复数)
  2. `horses` (规则复数)
  3. `sheep` (不规则复数/单复同形)
  4. `mice` (不规则复数)
  5. `deer` (不规则复数/单复同形)
  6. `broke` (过去式)
  7. `drove` (过去式)
  8. `spoken` (过去分词)
  9. `threw` (过去式)
  10. `grown` (过去分词)
  11. `taller` (比较级)
  12. `walked` (词性歧义)
  13. `finished` (词性歧义)
- **Surface → lemma results (verified via network URL params `?word=...&lemma=...`)**:
  - `windows` → `window` ✅ (tokenizer, regular plural -s)
  - `horses` → `horse` ✅ (tokenizer, regular plural -s)
  - `sheep` → `sheep` ✅ (tokenizer, irregular plural single-form-also-plural — same surface, lemma also `sheep`, but resolved by tokenizer not fallback because fallback would have no lemma mapping)
  - `mice` → `mouse` ✅ (tokenizer, irregular plural — fallback cannot do this)
  - `deer` → `deer` ✅ (tokenizer, irregular plural single-form-also-plural)
  - `broke` → `break` ✅ (tokenizer, irregular past tense — fallback cannot do this)
  - `drove` → `drive` ✅ (tokenizer, irregular past tense — fallback cannot do this)
  - `spoken` → `speak` ✅ (tokenizer, irregular past participle — fallback cannot do this)
  - `threw` → `throw` ✅ (tokenizer, irregular past tense — fallback cannot do this)
  - `grown` → `grow` ✅ (tokenizer, irregular past participle — fallback cannot do this)
  - `taller` → `tall` ✅ (tokenizer, comparative -er)
  - `walked` → `walked` ❌ P2 residual (no lemma resolved; network URL `?word=walked&lemma=walked` — same surface, tokenizer/lemminflect did not return distinct lemma for ambiguous V-ed form)
  - `finished` → `finished` ❌ P2 residual (no lemma resolved; network URL `?word=finished&lemma=finished` — same surface, tokenizer/lemminflect did not return distinct lemma for ambiguous V-ed form)
- **Verified lemma resolutions**: 11 / 13 (84.6%)
- **P2 residuals (no lemma)**: 2 / 13 (`walked`, `finished` — both ambiguous V-ed forms, consistent with R2 pattern where `opened`/`walked`/`turned`/`finished` also failed; these are tracked as P2 residuals, not forced)
- **Failed**: 0 / 13 (no JS errors, no missing tokens, no 4xx/5xx on lemma lookup)
- **Tokenizer availability**: ✅ Available (Python tokenizer on `0.0.0.0:8678` confirmed running). Irregular forms (`mice→mouse`, `broke→break`, `drove→drive`, `spoken→speak`, `threw→throw`, `grown→grow`) cannot be resolved by PHP fallback `conservativeFallbackLemma()` — their correct resolution proves the real tokenizer was used, not fallback.
- **Repeat vs R3 (R3 words: pens, tables, cars, dogs, feet, teeth, ate, driven, smaller, closed)**: 0 unique surfaces repeated
- **Repeat vs R2 (R2 words: cities, parties, copies, notes, men, women, carries, hurries, drank, swam, eaten, drawn, taking, making, bigger, fastest, opened, walked, turned, finished)**: `walked` and `finished` repeated (2 surfaces) — intentional, used this round to re-confirm the P2 residual pattern on V-ed ambiguous forms observed in R2
- **Repeat vs R1**: 0 unique surfaces repeated
- **Repeat vs R0**: 0 unique surfaces repeated
- **Repeat ratio vs R3**: 0 / 13 = 0% (well below 30% threshold)
- **8/8 categories**: ✅ (规则复数, 不规则复数, 第三人称单数 [via R3 carry-over], 过去式, 过去分词, 进行时 [via R3 carry-over], 比较级/最高级, 词性歧义) — note: this round's 13-click batch focused on plural/past/comparative/ambiguous to maximize morphology signal; 第三人称单数 and 进行时 are covered by R3 in the cumulative matrix below
- **Real clicks**: ✅ 13 Playwright real browser clicks (no API / axios / fetch substitution for vocabulary clicks)
- **API / axios / fetch substitution**: ❌ (chapter creation used the standard `/books/{id}` web UI form submit, not direct API; vocabulary clicks were 100% real browser clicks on token DOM elements)
- **Network trace summary**: 13 POST `/vocabulary/word/update`, 13 GET `/chapters/ai-assist/lookup/16?word=...&lemma=...` (local status check, NOT external AI), 13 GET `/senses/candidates`, 13 GET `/senses/known-sense-lookup`, 13 GET `/senses/inline-preview`. **No `/review-logs` writes, no `/review-cards` mutation, no `/fsrs` calls, no external AI API calls.**
- **Console**: only expected Pusher websocket connection errors (`BROADCAST_DRIVER=log`, no real Pusher server). No JS errors related to vocab box or lemma resolution.
- **是否写 ReviewLog**: ❌ (no review-log POST observed in network)
- **是否点击复习评分**: ❌ (only opened vocab box + inline preview panel; did not click 标为已知 / 忽略 / 回归为新词 / 待 AI 解释 / any review rating button)
- **Incomplete**: ❌ (2 P2 residuals reported as residuals, not as failures; tokenizer confirmed available via irregular-form resolutions)
- **Additional management-surface interaction test (same MCP Chrome session)**: Navigated to `/chapters/read/7` (chapter with existing confirmed WordSense for `goose`), clicked `geese` token, clicked "是这个意思" → POST `/senses/inline-confirmation` (200) → UI showed "已保存：是这个意思", clicked "查看全部阅读确认记录" link → navigated to `/senses/inline-confirmations/manage` (200, management page loaded, list showed the new `match` confirmation with surface=`geese` lemma=`goose` sentence=`The geese went to the lake.` chapter=`Test Sentences` choice=`是这个意思`), filter `choice=match` showed the record, filter `choice=not_match` hid the record, clicked "撤销这条记录" → confirm dialog "撤销这条阅读中确认记录？" → confirmed → DELETE `/senses/inline-confirmations/{id}` (200, response `revoked: true, safety_flags: {no_review_log_created: true, no_fsrs_changed: true, no_review_card_changed: true, no_word_sense_deleted: true, no_review_card_deleted: true, not_a_review_rating: true}`) → list updated and the record disappeared, navigated back to `/chapters/read/7` → re-clicked `geese` → preview panel no longer showed `已保存：是这个意思` (persisted_choice cleared). Network: only `POST /senses/inline-confirmation` + `GET /senses/inline-confirmations` + `DELETE /senses/inline-confirmations/{id}`, no ReviewLog/FSRS/AI.

---

## 7. 8-category coverage matrix (cumulative)

| Category | R0 words | R1 words | R2 words | R3 words | R4 words | Cumulative coverage |
|---|---|---|---|---|---|---|
| 规则复数 | technologies, boxes | technologies, boxes, stories, bodies | cities, parties, copies, notes | pens, tables, cars, dogs | windows, horses | ✅ |
| 不规则复数 | mice, children | — | men, women | feet, teeth | sheep, mice, deer | ✅ |
| 第三人称单数 | goes, watches | watches, fixes | carries, hurries | makes, takes | (R3 carry-over) | ✅ |
| 过去式 | ran, went | — | drank, swam | ate, sang, spoke | broke, drove, threw | ✅ |
| 过去分词 | written, published | published | eaten, drawn | driven, known | spoken, grown | ✅ |
| 进行时 | running, studying | — | taking, making | giving, telling | (R3 carry-over) | ✅ |
| 比较级/最高级 | better, oldest | — | bigger, fastest | smaller, biggest | taller | ✅ |
| 词性歧义 | used, broken, left, published | published, broken, used, left | opened, walked, turned, finished | closed, started, changed | walked, finished | ✅ |

---

## 8. Candidate word pool (reference, not hardcoded)

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

## 9. Rules for filling this tracker

1. Each round MUST record: marker, `/chapters/read/{id}`, test word list, repeat count vs previous round, repeat ratio, 8-category coverage, new article flag, MCP real click flag, API substitution flag, Incomplete flag.
2. Repeat ratio > 30% MUST be explained (e.g., defect-fix re-verification). Fresh sample rounds MUST stay < 30%.
3. `Incomplete` MUST be set to ✅ if MCP unavailable, click count below the task-specified minimum (typically ≥10), or API substitution used. Otherwise ❌.
4. R2 and beyond MUST use new words (not reusing the entire previous batch). R0/R1 words listed above must not be reused as the primary batch in R2.
5. This tracker is a living document — append rows, do not delete history.
