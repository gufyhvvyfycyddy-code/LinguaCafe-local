# Dictionary Read Path Contract

Status: active module contract  
Scope: dictionary settings list/detail, local lookup, hover lookup, inflections, Mobile connected lookup, API providers, record count, and the read-only dictionary doctor.

## 1. Responsibility

Dictionary reads must remain available when one configured dictionary is missing, malformed, duplicated, or temporarily failing. A broken dictionary is isolated; it must not hide healthy results or crash the administration list.

This contract does not change dictionary import, replacement, delete, repair, or migration behavior.

## 2. Health classification

`DictionaryHealthService` classifies metadata using read-only schema inspection. The public health object is:

```json
{
  "status": "healthy|disabled|missing_table|invalid_schema|duplicate_target|incomplete_group|metadata_missing|conflicting_generation|unknown",
  "code": "STABLE_MACHINE_CODE",
  "message": "safe user-facing message",
  "query_available": true,
  "repair_required": false
}
```

Rules:

- API metadata has no local physical table requirement.
- A disabled row still reports its factual physical health, but `query_available=false`.
- Ordinary imported dictionaries require `word` and `definitions` columns.
- The canonical JMDict target is identified by `database_table_name=dict_jp_jmdict`, not by display name.
- JMDict is one five-table group: `dict_jp_jmdict`, `dict_jp_jmdict_words`, `dict_jp_jmdict_readings`, `dict_jp_kanji`, and `dict_jp_kanji_radicals`.
- If any required JMDict table is missing or structurally invalid, the whole group is `incomplete_group` and is not queried.
- Multiple metadata rows owning one non-API target are all `duplicate_target` and unavailable for reads.

The administration list remains HTTP 200 when individual rows are unhealthy. Healthy rows retain record counts; unhealthy rows use `records=null`. A missing dictionary ID returns HTTP 404 with `DICTIONARY_NOT_FOUND`.

## 3. Lookup envelopes

### Local ordinary search

```json
{
  "term": "normalized term",
  "results": [],
  "warnings": [],
  "configured": true
}
```

### Hover and Mobile local lookup

```json
{
  "term": "normalized term",
  "definitions": [],
  "warnings": [],
  "configured": true
}
```

### JMDict inflections

```json
{
  "term": "normalized term",
  "inflections": [],
  "warnings": [],
  "configured": true
}
```

### API provider search

```json
{
  "term": "normalized term",
  "results": [],
  "warnings": [],
  "configured": true
}
```

Availability semantics:

- No applicable configured dictionary/provider: HTTP 200, empty data, `configured=false`.
- At least one applicable healthy query executes: HTTP 200. Healthy results and safe warnings may coexist.
- Applicable configuration exists but every candidate is unavailable or fails: HTTP 503, `error.code=DICTIONARY_LOOKUP_UNAVAILABLE`.
- Warnings contain only `dictionary_id`, `dictionary_name`, stable `code`, and safe `message`.
- Warnings and errors must not expose SQL, physical target names, file paths, API hosts, keys, raw provider payloads, or exception messages.

Mobile preserves the `MobileApiResponse` outer contract and places the same term, definition, warning, and configured semantics in its data payload.

## 4. Input authority

`DictionaryLookupRequestPolicy` is shared by Web request classes and Mobile lookup.

- Trim before lookup.
- Reject empty or whitespace-only terms.
- Reject NUL and Unicode control characters.
- Maximum length is 100 Unicode characters after trimming.
- The authenticated user's `selected_language` is the only language authority. Client-supplied language fields cannot override it.
- The normalized term is returned in the response.

## 5. Result policy

- Ordinary imported rows split definitions consistently on `;`, then trim and remove empty values.
- Repeated ordinary rows for one word merge definitions in first-seen order.
- Hover/Mobile definitions are deduplicated before truncation and capped at 10.
- JMDict definitions are atomic strings. Commas inside one definition are not delimiters.
- Full ordinary search keeps the existing per-table limit of 40 records.

## 6. Provider isolation

Each enabled provider is called independently. Timeout, connection failure, non-2xx response, malformed JSON, missing required fields, or request-builder failure affects only that provider. Other provider results remain available. Tests must use `Http::fake()`; automated tests must not contact real providers.

## 7. Record-count boundary

Record count is allowed only for exactly one metadata-owned, healthy dictionary target. Core application tables, unknown targets, duplicate targets, API rows, and `_stage_`, `_backup_`, or `_failed_` auxiliary targets are rejected with a stable safe error. JMDict count is allowed only through its canonical main target.

## 8. Dictionary doctor

`GET /dictionaries/doctor` is authenticated and administrator-only. It is a dry-run read model that reports:

- metadata health and suggested manual action;
- duplicate target classification;
- orphan `dict_*` targets as `metadata_missing`;
- JMDict group state;
- a deterministic SHA-256 `evidence_hash` over stable evidence.

It does not repair, apply, insert, update, delete, rename, drop, or otherwise mutate database state. `generated_at` is informational and is excluded from the evidence hash.

## 9. Frontend degraded behavior

- Administration loading always ends on success or failure.
- Healthy and unhealthy dictionary rows can be displayed together, with a row-level repair message.
- Ordinary lookup distinguishes no configuration, no matching definition, partial degradation, and total outage.
- Partial warnings never clear healthy results.
- Search request sequencing prevents an older response from replacing a newer term.
- Hover and inflection failures always leave loading/state in a completed form.
- Reader API and response-policy modules own transport and envelope normalization; Vue components own presentation and current-request orchestration.
