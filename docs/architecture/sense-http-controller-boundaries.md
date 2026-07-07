# Sense HTTP Controller Boundaries

> **Status**: Current architecture contract.
> **Last updated**: 2026-07-07.
> **Implementation baseline**: `af4c439 refactor: extract manual word sense controller`.
> **Purpose**: Prevent future LinguaCafe sense/review features from growing back into a controller "god object".

This document is the current source of truth for where new sense / review-adjacent HTTP features should live. It overrides older historical notes that still mention `SenseOccurrenceController::storeManualSense`, `SenseOccurrenceController::sourceContextList`, or inline confirmation methods on `SenseOccurrenceController`.

---

## 1. Product language

The user-facing product term for occurrence review work is:

**处理待确认词义**

Use this wording in future UX copy, task prompts, and docs when referring to pending `WordSenseOccurrence` confirmation / binding / rejection work. Avoid exposing "occurrence" to normal users unless it is a developer-only context.

Source context / 查看原文 / 来源上下文 is classified as:

**复习辅助功能**

It is not a reader-only feature. Future naming should avoid moving it under a pure `Reader*Controller` unless the product scope changes explicitly.

---

## 2. Current controller map

| Product area | Controller | Routes | Primary service(s) | Writes? | Notes |
|---|---|---|---|---:|---|
| 待确认词义查询 / candidate lookup | `SenseOccurrenceController` | `GET /senses/occurrences`, `GET /senses/candidates`, `GET /senses/possible-duplicates` | `WordSenseOccurrenceService`, `SenseOccurrencePayloadSerializerService` | No, except service internals if explicitly called by future change | This controller should remain read/query oriented. Do not add new write actions here. |
| 已学词义候选 / inline preview | `SenseOccurrenceController` | `GET /senses/known-sense-lookup`, `GET /senses/inline-preview` | `WordSenseKnownSenseService` | No | Preview is read-only and must not write ReviewLog / FSRS / WordSense / ReviewCard / AI. |
| 例句查看 | `SenseOccurrenceController` | `GET /senses/{id}/examples` | `SenseOccurrenceExampleService` | No | The controller delegates query + payload to the service. |
| 阅读中词义确认 | `ReadingInlineSenseConfirmationController` | `POST /senses/inline-confirmation`, `GET /senses/inline-confirmations`, `POST /senses/inline-confirmations/undo`, `DELETE /senses/inline-confirmations/{id}` | `ReadingInlineSenseConfirmationService`, `WordSenseKnownSenseService` | Yes, only the `reading_inline_sense_confirmations` table | Not a review rating. No ReviewLog, no FSRS, no WordSense/ReviewCard creation. |
| 手动词义 / 归档 | `ManualWordSenseController` | `POST /senses/manual`, `PUT /senses/{id}/manual`, `PUT /senses/{id}/archive` | `WordSenseService`, `SenseOccurrencePayloadSerializerService` | Yes | Owns manual WordSense creation/edit/archive HTTP entrypoints. |
| 复习辅助来源上下文 | `SenseSourceContextController` | `GET /senses/{id}/source-context`, `GET /senses/{id}/source-context-list` | `SenseSourceContextService` | Conditional service write-back only | HTTP wrapper only. Do not change recovery/write-back semantics here. |
| 单条待确认词义动作 | `SenseOccurrenceActionController` | `POST /senses/occurrences/{id}/confirm`, `bind`, `create-sense`, `reject`, `ignore` | `WordSenseOccurrenceService`, `SenseOccurrencePayloadSerializerService` | Yes via service | Handles one occurrence at a time. Must keep user/language scoping. |
| 批量待确认词义动作 | `SenseOccurrenceBulkActionController` | `POST /senses/occurrences/bulk-confirm`, `bulk-ignore`, `bulk-reject`, `bulk-confirm-high-confidence` | `WordSenseOccurrenceService` | Yes via service | Handles many occurrences. Do not merge back into query controller. |

---

## 3. Hard placement rules for future features

1. New write endpoints must not be added to `SenseOccurrenceController`.
2. New read endpoints may use `SenseOccurrenceController` only when they are strictly about occurrence listing, candidate lookup, duplicate lookup, or read-only preview.
3. If a feature has its own product noun, it gets its own Controller before implementation.
4. If a Controller needs more than one unrelated service family, stop and create a smaller boundary first.
5. A Controller may validate requests, read `Auth::user()->id`, read `Auth::user()->selected_language`, call one service, and return JSON.
6. A Controller must not own large response-shape arrays. Put payload shape in a serializer/service.
7. A Controller must not contain direct DB query + business mutation + payload assembly in the same method.
8. Route paths are product contracts. Controller moves must preserve paths unless a route migration plan is explicitly approved.
9. Source context is a review-assist capability. Do not classify it as reader-only when designing future work.
10. "处理待确认词义" is the product-facing phrase for occurrence confirmation work.

---

## 4. Future feature placement examples

| Future feature | Correct home | Forbidden shortcut |
|---|---|---|
| Add a new filter to pending sense occurrence list | `SenseOccurrenceController@index` + `WordSenseOccurrenceService::listOccurrences()` | Add filter logic inside an action controller |
| Add a new single-occurrence action | `SenseOccurrenceActionController` or a new narrower controller if product semantics differ | Add method to `SenseOccurrenceController` |
| Add a new bulk operation for pending senses | `SenseOccurrenceBulkActionController` | Add method to `SenseOccurrenceController` |
| Add a manual sense field or validation rule | `ManualWordSenseController` + `WordSenseService` | Put manual validation back in `SenseOccurrenceController` |
| Change source context UI behavior | `SenseSourceContextController` only for HTTP wrapper; service owns behavior | Move it to a reader controller without product approval |
| Add reading inline confirmation statistics | `ReadingInlineSenseConfirmationController` + service | Write statistics in inline preview controller method |
| Add AI-generated study card workflow | A dedicated `AiStudyCard*Controller` family + ADR/plan first | Reuse `SenseOccurrenceController` because it already has sense routes |
| Add real reading-inline review rating | New ADR + new Controller/service boundary | Reuse inline confirmation endpoints as ratings |

---

## 5. Service boundary rules

| Service | Owns | Must not own |
|---|---|---|
| `WordSenseOccurrenceService` | Pending occurrence query/action semantics | HTTP validation, route decisions, UI copy |
| `WordSenseService` | WordSense create/update/archive and review-card side effects that already belong to manual sense lifecycle | Reading inline confirmation persistence |
| `SenseOccurrencePayloadSerializerService` | Stable occurrence/sense response payloads and list normalization | DB mutation, route handling |
| `SenseOccurrenceExampleService` | Example query + example payload for a sense | Source context recovery/write-back |
| `SenseSourceContextService` | Source context resolution, fallback, preferred occurrence, recovery/write-back fields | Controller route ownership, front-end dialog state |
| `ReadingInlineSenseConfirmationService` | Reading inline confirmation persistence, management list, undo token semantics | FSRS rating, ReviewLog, WordSense/ReviewCard creation |
| `WordSenseKnownSenseService` | Confirmed sense lookup, read-only inline preview payload | Writes or AI judgment |

---

## 6. Required architecture checks for every new feature

Before a feature task is approved, the prompt must answer:

1. Which product area does this feature belong to?
2. Which existing Controller owns the HTTP entrypoint?
3. If no Controller fits cleanly, what new Controller will be created first?
4. Which service owns business logic?
5. Which serializer/service owns response shape?
6. Does the feature write ReviewLog, FSRS, WordSense, ReviewCard, or AI-related state?
7. Which existing routes must remain stable?
8. Which guard test prevents the feature from drifting into the wrong Controller?
9. Does the feature require MCP Chrome real browser validation?

If these questions cannot be answered, the next task is architecture setup, not feature implementation.

---

## 7. Required tests / guards

Every new Controller or Controller move must include:

1. A syntax check for modified/new Controllers.
2. A feature test or existing route test proving the old path still works.
3. An architecture guard test proving the old Controller no longer owns the moved methods.
4. An architecture guard test proving the new Controller owns the expected methods.
5. A route guard proving the original route path points to the intended Controller.
6. A forbidden-route guard proving the old Controller is not referenced for the moved route.

For browser-visible changes, MCP Chrome real validation is required. API 200, code review, or screenshots alone do not replace real page operation.

---

## 8. Current remaining `SenseOccurrenceController` scope

As of `af4c439`, `SenseOccurrenceController` should remain limited to:

- `index()` — pending occurrence list query.
- `candidates()` — candidate senses for lemma.
- `knownSenseLookup()` — confirmed sense lookup.
- `inlinePreview()` — read-only preview for reading page.
- `possibleDuplicates()` — duplicate lookup.
- `examples()` — thin wrapper to `SenseOccurrenceExampleService`.
- Thin serializer delegation helpers only.

Do not add manual sense, archive, source context, inline confirmation, single action, or bulk action methods back into this Controller.

---

## 9. Current closure status

`SenseOccurrenceController` was reduced from a multi-responsibility controller to a query/preview wrapper through these commits:

- `ba75783 architecture-cleanup` — extracted payload serializer.
- `dec9ff4 refactor: extract sense occurrence examples service` — extracted examples service.
- `bd68620 refactor: extract inline confirmation controller` — extracted inline confirmation HTTP entrypoints.
- `646b225 refactor: extract source context controller` — extracted review-assist source context HTTP entrypoints.
- `9eeb573 refactor: extract sense occurrence action controller` — extracted single occurrence actions.
- `02cfbc9 refactor: extract sense occurrence bulk action controller` — extracted bulk occurrence actions.
- `af4c439 refactor: extract manual word sense controller` — extracted manual sense / archive actions.

Current code debt score after closure: approximately **3.4 / 10**. Remaining risk is low, but the architecture must be protected by guard tests and this document.
