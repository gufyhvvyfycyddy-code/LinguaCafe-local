# AI Study Card V6 Real Provider Network Smoke Playbook

> **Status**: Playbook only. Real provider UI is not implemented yet.
> **Date**: 2026-07-07.
> **Use when**: A future task adds a live provider UI trigger or live provider route.

---

## 1. Purpose

This playbook defines the browser validation required before accepting any AI Study Card V6 live-provider integration.

API tests, curl, route checks, screenshots, and code review do not replace this smoke. The point is to prove the real browser does not leak secrets, does not call provider domains directly, and does not trigger provider calls before explicit user action.

---

## 2. Local account

Use the current task prompt's local test account and password.

Do not commit the concrete password to documentation.

---

## 3. Required route expectations

The future live-provider UI should call only the local backend route:

`POST /ai-study-card/v6/recommendations/provider-preview`

Browser Network must not call provider domains directly from Vue/JavaScript.

---

## 4. Pre-click negative checks

Before clicking any live provider button, validate these browser actions do **not** create a provider request:

1. Open `/login`.
2. Login.
3. Open a readable chapter.
4. Click a word.
5. Click `待 AI 解释`.
6. Open `待 AI 解释列表`.
7. Open the preview dialog.
8. Select and deselect pending items.
9. Generate the V6 request package.
10. Copy the V6 request package.

Expected result before live-provider click:

- no provider-preview route call
- no external provider domain call
- no secret value in Network
- no `WordSense`, `ReviewCard`, `ReviewLog`, or FSRS mutation from provider flow

---

## 5. Explicit provider click checks

After the future provider button exists:

1. Clear Network log.
2. Click the explicit provider button once.
3. Confirm the browser sends exactly one local request to the provider-preview backend route.
4. Confirm the browser does not call provider domains directly.
5. Confirm request payload contains no secret value.
6. Confirm response payload contains no secret value.
7. Confirm provider failure returns a fail-closed message.
8. If recommendations are returned, confirm they appear unchecked.
9. Confirm AI reason is shown only as reference text.
10. Confirm final card creation still requires V5 user confirmation.

---

## 6. Required evidence

The WorkBuddy report must include:

- viewport size
- login result
- page path
- clicked word
- pending item state
- whether provider button was visible
- Network requests before click
- Network request after click
- whether any provider domain appeared
- whether any secret-like value appeared
- whether recommendations defaulted unchecked
- whether `生成学习卡` was not triggered automatically
- screenshots or text descriptions of the key UI states

---

## 7. Fail conditions

Mark Refuse if any of these occur:

- provider call happens on page load
- provider call happens on token click
- provider call happens when opening pending list or preview dialog
- frontend calls provider domain directly
- Network exposes a secret value
- provider output creates a card without V5 confirmation
- provider output writes ReviewLog or changes FSRS
- AI reason becomes final `sense_zh` automatically
- recommendations default checked
- test substitutes API/curl for browser Network validation

---

## 8. Incomplete conditions

Mark Incomplete if:

- login fails and cannot be recovered
- no readable chapter is available
- pending item cannot be created or opened
- provider button is not implemented yet
- Network cannot be inspected
- browser automation fails before provider-click checks

Incomplete is acceptable before the live provider UI exists. Do not invent results.

---

## 9. Report format

The WorkBuddy report must include:

1. Expert used: 网页端体验师
2. Single expert only: yes/no
3. Conclusion: Accept / Refuse / Incomplete
4. Login result
5. Browser path and clicks
6. Pre-click Network result
7. Provider-click Network result
8. Secret exposure result
9. UI result
10. Safety result
11. Screenshots/evidence
12. Forbidden-scope confirmation
13. Whether it entered the next task: no
