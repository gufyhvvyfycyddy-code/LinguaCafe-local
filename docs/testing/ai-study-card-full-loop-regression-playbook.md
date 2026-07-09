# AI Study Card Full-Loop Regression Playbook

> **Status**: Active. Replaces ad-hoc chat-only acceptance for the AI Study Card main chain.
> **Date**: 2026-07-09.
> **Use when**: Any change touches AI Study Card V6 / V4 / V5 / sense review rating / FSRS / WordSense / ReviewCard / ReviewLog on the main chain.
> **Audience**: GLM / OpenCode / CodeBuddy / WorkBuddy agents, plus human reviewers.

---

## 1. Purpose

This playbook is the single source of truth for verifying the AI Study Card main chain:

```
V6 provider-preview  →  V4 final-candidates-package  →  V5 generate-cards
                                                                    ↓
                                          /reviews/senses queue  →  /reviews/senses/{id}/rate
```

Future agents who change anything on this chain must run the test command matrix (§3), follow the MCP Chrome real acceptance path (§4), record the database count deltas (§5), verify the network boundaries (§6), and apply the Refuse conditions (§7) before claiming Accept.

API tests, curl, route checks, backend smoke, and chat reports do **not** replace this playbook. The point is to prove the real browser still walks the full chain safely, that the database only changes in allowed ways, and that no external provider domain is contacted.

---

## 2. Pre-flight

Before running any verification:

```bash
git fetch origin master
git status --short --branch
git log -10 --oneline
```

Confirm:

- Current branch is `master` (or a clean feature branch based on latest `master`).
- Working tree only contains the intended changes.
- `.env` may show as modified — that is expected; **never read, print, modify, or commit `.env`**.
- No temporary files, no test report files, no WorkBuddy report files staged.

If any of these fail, stop and report — do not proceed to verification.

---

## 3. Test Command Matrix

Run these commands from the project root. Each must finish green before moving to the next. Use `--stop-on-failure` so the first regression halts the run.

### 3.1 New full-loop index guard (start here — fastest main-chain signal)

```bash
php artisan test --filter=AiStudyCardFullLoopGuardTest --stop-on-failure
```

Expected: `3 passed` (84 assertions). Covers points 1-12 of the safety contract matrix (§7.1) in a single file.

### 3.2 V5 dialog / result UI source-string guards

```bash
php artisan test --filter=VocabularyBoxV5UiGuardTest --stop-on-failure
```

Expected: `20 passed` (166 assertions). Locks V5 dialog copy, per-candidate generate/skip chip, confirm-button-disabled-when-zero-filled, `/reviews/senses` entry copy, and AI-reason-not-auto-filled-into-sense_zh source contracts.

### 3.3 V6 provider / request-package / preflight / adapter guards

```bash
php artisan test --filter=AiStudyCardV6 --stop-on-failure
```

Expected: `79 passed` (799 assertions). Locks V6 provider-preview fail-closed behaviour, request-package schema, prompt/response contract, adapter, transport config, and security boundary.

### 3.4 Sense review runtime guards (queue / daily limits / example rotation)

```bash
php artisan test --filter=SenseReview --stop-on-failure
```

Expected: `29 passed` (129 assertions). Locks `/reviews/senses` queue semantics, daily-limit override, and example rotation.

### 3.5 FSRS scheduling + sense card rating guards

```bash
php artisan test --filter=ReviewFsrsTest --stop-on-failure
```

Expected: `63 passed` (374 assertions). Locks FSRS state transitions, sense card rating, cross-user rejection, and `source=sense_review` ReviewLog contract.

### 3.6 WordSense + closed-loop guards

```bash
php artisan test --filter=WordSense --stop-on-failure
```

Expected: `197 passed, 1 skipped` (820 assertions). Locks WordSense CRUD, sense review queue, manual sense add, FSRS doctor, lemma fallback, and the V5→/reviews/senses→rate closed loop.

### 3.7 V5 generate-cards runtime guards

```bash
php artisan test --filter=AiStudyCardPendingItemTest --stop-on-failure
```

Expected: 50+ passed. Locks V5 generate-cards creates target_type=sense ReviewCard, no ReviewLog, no legacy word card, AI reason not saved as sense_zh, and reverse-validation skips.

### 3.8 Frontend build

```bash
npm run development
```

Expected: `webpack compiled successfully` with exit code 0. No TypeScript errors, no Sass errors, no unresolved imports.

### 3.9 Quick combined run (for re-verification after small changes)

```bash
php artisan test --filter='AiStudyCardFullLoopGuardTest|VocabularyBoxV5UiGuardTest|AiStudyCardV6|SenseReview|ReviewFsrsTest|WordSense'
```

Expected: `391 passed, 1 skipped` (2372 assertions). Single-command sanity check for the main chain.

---

## 4. MCP Chrome Real Acceptance Playbook

This is the **browser** acceptance path. Backend smoke does not substitute. If MCP Chrome DevTools is unavailable, mark the run **Incomplete** and request a human or agent with MCP Chrome to re-run.

### 4.1 Lightweight acceptance (when only tests / docs changed)

If the change is purely additive tests or documentation with no business code change, run only:

1. `npm run development` succeeded.
2. `php artisan serve` is running on `127.0.0.1:8000`.
3. MCP Chrome navigates to `http://127.0.1:8000/reviews/senses`.
4. Page returns 200, no console error, no unauthenticated redirect.
5. The `词义复习` heading renders.
6. The bottom card area either shows a card or shows `当前没有到期词义卡。`.
7. Network panel shows only `127.0.0.1:8000` requests — no external provider domain.

If all 7 pass, lightweight acceptance is complete. Document why full acceptance was not re-run (e.g. "no business code changed, only `tests/Feature/AiStudyCardFullLoopGuardTest.php` and `docs/testing/*` were added").

### 4.2 Full acceptance (when business code or UI changed)

Walk all 20 steps in order. Stop and Refuse on the first violation.

1. **Login**: Navigate to `/login`, log in with the local test account. Never commit the password to docs.
2. **Open reader**: Navigate to a readable chapter (e.g. an English chapter that has been imported and processed).
3. **Click word**: Click a word in the reader body. The VocabularySideBox / VocabularyBox opens.
4. **Add to AI pending**: Click `待 AI 解释`. A pending item is created (no WordSense, no ReviewCard, no ReviewLog).
5. **Open pending list**: Open the `待 AI 解释列表` panel.
6. **Generate V6 request package**: Click the V6 request-package button. A preview package appears. No external provider call.
7. **Provider-preview or paste**: Either trigger `provider-preview` (which fails closed while security preconditions are not met) or paste AI recommendation JSON into the V4 input.
8. **Import V4**: Import the AI recommendation list into the V4 candidate list.
9. **Default unchecked**: Confirm the imported AI recommendations appear **unchecked**. The user-selected items appear checked.
10. **Manual select**: Tick one or more AI recommendations to include them in the final candidates.
11. **Generate final candidates**: Click the button to build the `ai-study-card-final-candidates-v1` package. No WordSense / ReviewCard / ReviewLog created.
12. **Open V5 dialog**: Click `确认生成学习卡`. The V5 dialog opens with the candidate list.
13. **Fill 1 Chinese definition**: Type a Chinese definition into exactly one candidate's `中文释义（必填）` field.
14. **Per-candidate status**: Confirm the filled candidate shows `将生成` and the others show `将跳过`. Confirm the bottom counter shows `共 X 项，将生成 1 张，将跳过 X-1 项`.
15. **Confirm**: Click `确认生成 1 张学习卡`. The V5 result panel appears with a candidate overview, created/skipped/duplicate/failed counts, and a `进入 /reviews/senses 复习` entry.
16. **Enter /reviews/senses**: Click the entry. Navigate to `/reviews/senses`.
17. **Find target card**: The newly generated sense card is immediately in the queue (fsrs_state=new, fsrs_due_at=now). Confirm it appears in the queue list.
18. **Controlled rating**: Click `显示答案`, then click `记得` (good). The card advances to `review` / `learning` with `fsrs_reps=1`.
19. **Database delta check**: After rating, run the SQL or `php artisan tinker` checks from §5.6.
20. **Network check**: Open DevTools Network panel and confirm:
    - All requests target `127.0.0.1:8000`.
    - No request to `api.deepseek.com`, `api.openai.com`, `api.anthropic.com`, `generativelanguage.googleapis.com`, or `api.x.ai`.
    - No request URL, header, or body contains `Authorization: Bearer ...`, `api-key`, `sk-`, or any secret-like value.

---

## 5. Database Acceptance Matrix

Use this matrix to judge whether database writes at each stage are within the safety contract. Counters refer to the current user's rows (or all rows if the counter is global — note which).

### 5.1 V6 request-package (browser-side copy)

| Table | Expected delta |
|---|---|
| `word_senses` | 0 |
| `review_cards` | 0 |
| `review_logs` | 0 |
| `ai_study_card_pending_items` | 0 (no status change either) |

### 5.2 V6 provider-preview

| Table | Expected delta |
|---|---|
| `word_senses` | 0 |
| `review_cards` | 0 |
| `review_logs` | 0 |

Provider-preview is read-only with respect to learning data. The endpoint may log to `ai_study_card_v6_provider_logs` (audit log) — that is allowed.

### 5.3 V4 final-candidates-package

| Table | Expected delta |
|---|---|
| `word_senses` | 0 |
| `review_cards` | 0 |
| `review_logs` | 0 |
| `ai_study_card_pending_items` | 0 (no status change either) |

V4 is a packaging step, not a generation step.

### 5.4 V5 generate-cards (with N filled candidates)

| Table | Expected delta |
|---|---|
| `word_senses` | +N (one confirmed sense per filled candidate) |
| `review_cards` (target_type=sense) | +N |
| `review_cards` (target_type=word, legacy) | **0** — Refuse if any legacy word card is created |
| `review_logs` | **0** — Refuse if any ReviewLog is written |
| `ai_study_card_pending_items` | processed for each created candidate (status flip from `pending` to `processed`) |
| `word_sense_occurrences` | +N if sentence_id / chapter_id / text_block_index are present; 0 if source info is insufficient |

### 5.5 Sense review rating (one card, one rating)

| Table | Expected delta |
|---|---|
| `word_senses` | **0** — Refuse if any new WordSense is created |
| `review_cards` (target_type=sense) | **0** — Refuse if any new sense card is created |
| `review_cards` (target_type=word, legacy) | **0** — Refuse if any legacy word card is created |
| `review_logs` | **+1** with `source=sense_review`, `review_card_id` = target card id, `rating` = submitted rating |
| Target `review_cards` row | `fsrs_reps` +1, `fsrs_state` leaves `new`, `fsrs_stability` / `fsrs_difficulty` / `fsrs_last_reviewed_at` set |
| Other `review_cards` rows | unchanged |

### 5.6 Quick SQL / tinker check after rating

```bash
php artisan tinker
>>> $cardId = <target card id>;
>>> App\Models\ReviewCard::find($cardId)->only(['fsrs_state','fsrs_reps','fsrs_stability','fsrs_difficulty','fsrs_last_reviewed_at']);
>>> App\Models\ReviewLog::where('review_card_id', $cardId)->orderByDesc('id')->first()->only(['rating','source','review_card_id']);
>>> App\Models\WordSense::count();
>>> App\Models\ReviewCard::count();
>>> App\Models\ReviewCard::where('target_type','word')->count();
>>> App\Models\ReviewLog::count();
```

Before the rating run, snapshot the four counters. After the rating, the deltas must match §5.5.

---

## 6. Network Acceptance

The browser must only talk to `127.0.0.1:8000` (or whichever local port the dev server uses). The following external provider domains are **forbidden** in browser Network at any point in the main chain:

- `api.deepseek.com`
- `api.openai.com`
- `api.anthropic.com`
- `generativelanguage.googleapis.com`
- `api.x.ai`

If any of these appear in Network, **Refuse** — even if the request failed. The main chain must not directly call external providers from the browser. Provider calls (when implemented) are server-side only, gated by V6 security preconditions, and never exposed to the frontend.

Secret material that must never appear in any Network URL, header, or body:

- API keys (`sk-...`, `sk-ant-...`, `deepseek-...`, `xai-...`)
- Bearer tokens
- `.env` contents
- Authorization headers
- `api-key` headers

---

## 7. Refuse Conditions

A run is **Refuse** if any of these occur. Do not try to "fix and re-run silently" — stop and report.

### 7.1 Safety contract matrix (12 points)

These are the 12 contract points the harness is designed to lock. A violation of any point is a Refuse.

| # | Point | Refuse if |
|---|---|---|
| 1 | V6 provider-preview does not write learning data | WordSense / ReviewCard / ReviewLog count increases after V6 preview |
| 2 | V4 requires user confirmation before card generation | V4 final-candidates-package creates a ReviewCard without an explicit V5 confirm |
| 3 | V4 reflects default-unchecked AI recommendations | AI recommendations are pre-checked on import; `ai_recommended_default_unchecked` is false or missing |
| 4 | V5 rejects empty sense_zh (fail-closed fallback) | V5 generate-cards accepts a candidate with empty `sense_zh`; or batch with mixed filled/empty silently creates the filled ones without 422 |
| 5 | V5 does not write ReviewLog | ReviewLog count increases after V5 generate-cards |
| 6 | V5 does not create legacy word card | `target_type=word` ReviewCard count increases after V5 generate-cards |
| 7 | V5 result is displayable | V5 response is missing `results.created`, `results.summary`, or `safety_flags` |
| 8 | New sense card immediately in /reviews/senses queue | Newly generated card with `fsrs_state=new` / `fsrs_due_at=now` / `fsrs_enabled=true` does not appear in `GET /reviews/senses?ignoreDailyLimits=1` |
| 9 | Rating creates exactly one ReviewLog | ReviewLog count delta is not +1 after one rating |
| 10 | Rating only updates target card | Any other ReviewCard's `fsrs_reps` / `fsrs_state` changes |
| 11 | Rating does not create new WordSense | WordSense count increases after rating |
| 12 | Rating does not create legacy word card | `target_type=word` ReviewCard count increases after rating |

### 7.2 Additional Refuse triggers

- AI reason is automatically saved as `sense_zh` (the user must type the Chinese definition manually).
- Browser Network shows any external provider domain.
- Browser Network or DOM exposes any secret value (API key, Bearer token, `.env` content).
- Agent only ran backend smoke (e.g. `curl` or `php artisan test`) and claimed browser acceptance.
- Agent faked MCP Chrome results without actually navigating the page.
- `.env` was read, printed, modified, or committed.
- `migrate:fresh`, `db:wipe`, or any DB-clearing command was run.
- A notification script (`notify.ps1` or similar) was executed.
- DCP (destructive change protocol) was triggered.
- The change touches more than the approved file boundary (see §9).

---

## 8. Accept / Refuse / Incomplete Judgment

### 8.1 Accept

The run is **Accept** if and only if:

- All commands in §3 finish green with the expected counts.
- `npm run development` exits 0.
- §4 (lightweight or full, depending on change scope) passes.
- §5 database deltas match the matrix.
- §6 network is clean.
- §7 has zero Refuse triggers.
- `.env` is not staged; `git status` shows only the intended files.

### 8.2 Refuse

The run is **Refuse** if any §7 trigger fires. Report the specific trigger, the failing assertion, the observed vs. expected value, and the file/line that caused it. Do not commit.

### 8.3 Incomplete

The run is **Incomplete** if:

- MCP Chrome is unavailable and the change scope requires browser acceptance (§4.2).
- A baseline test fails for a reason unrelated to the change (e.g. MariaDB is down) and cannot be safely recovered within the run.
- A stop condition from §10 is hit.

Report what was completed, what was not, and the blocker. Do not commit until the blocker is resolved and the run is upgraded to Accept.

---

## 9. Allowed File Boundary

This playbook is for verifying the main chain. The change under verification should normally touch only:

- `tests/Feature/*AiStudyCard*`
- `tests/Feature/*SenseReview*`
- `tests/Feature/WordSenseTest.php`
- `tests/Feature/AiStudyCardFullLoopGuardTest.php` (new index guard)
- `docs/testing/*`
- `docs/plans/linguacafe-master-plan.md`
- `docs/plans/current-working-handoff.md`
- `docs/DOCUMENTATION_INDEX.md`

If the change touches business code (`app/Http/Controllers/AiStudyCard*`, `app/Services/AiStudyCard*`, `resources/js/components/Text/AiStudyCard*`, `resources/js/services/AiStudyCard*`), the agent must:

1. Explain why business code had to change (existing code could not be tested / accepted otherwise).
2. Keep the change minimal.
3. Run the **full** MCP Chrome acceptance (§4.2), not just the lightweight path.
4. Update this playbook if the contract semantics change.

---

## 10. Stop Conditions

Stop immediately and report if:

1. Local page (`127.0.0.1:8000`) is unreachable.
2. Login fails.
3. MCP Chrome cannot perform real acceptance (e.g. browser does not launch, page does not render).
4. An auto test fails and the cause is not the change under verification (e.g. MariaDB service down).
5. `npm run development` fails and the cause cannot be safely fixed within the change scope.
6. The change requires reading or modifying `.env` to proceed.
7. The change requires `migrate:fresh`, `db:wipe`, or any DB-clearing command.
8. The change requires breaking the WordSense / ReviewCard / ReviewLog / FSRS boundaries documented in §5.
9. The agent is unsure whether a step will write learning data — stop and verify with a smaller test first.
10. The agent discovers the existing main chain is already broken (regression found before the change).

Do **not** "work around" stop conditions. Report them and wait for guidance.

---

## 11. Quick Reference: File-to-Test Map

| File / area | Primary guard test |
|---|---|
| `app/Http/Controllers/AiStudyCardV6RecommendationController` (provider-preview / request-package) | `AiStudyCardV6ProviderPreviewRouteTest`, `AiStudyCardV6RequestPackageTest` |
| `app/Services/AiStudyCard*` (pending items, V4, V5 generation) | `AiStudyCardPendingItemTest`, `AiStudyCardPendingLifecycleTest` |
| `app/Http/Controllers/SenseReviewController` (queue / rate) | `WordSenseTest::test_v5_generated_sense_card_is_immediately_reviewable_with_single_log_and_no_side_effects`, `ReviewFsrsTest`, `SenseReviewDailyLimitsTest` |
| `resources/js/components/Text/AiStudyCardGenerateCardsDialog.vue` (V5 dialog) | `VocabularyBoxV5UiGuardTest` |
| `resources/js/services/AiStudyCardGenerateCardsService.js` (filter / POST) | `VocabularyBoxV5UiGuardTest` (source string guards) + `AiStudyCardPendingItemTest` (runtime backend 422 fallback) |
| Full main chain (V6→V4→V5→/reviews/senses→rate) | `AiStudyCardFullLoopGuardTest` (new index guard) |

---

## 12. Change Log

- 2026-07-09: Initial version. Created alongside `tests/Feature/AiStudyCardFullLoopGuardTest.php` to lock the V6→V4→V5→/reviews/senses→FSRS rating main chain as a repeatable regression asset.
