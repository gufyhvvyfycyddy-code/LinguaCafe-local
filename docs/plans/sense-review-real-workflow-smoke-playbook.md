# SenseReview Real Workflow Smoke Playbook

> Status: Current local smoke playbook.
> Last updated: 2026-07-02.

This playbook records the low-risk harness used for the real SenseReview page workflow. It is for local verification only and must not be treated as permission to change SenseReview, FSRS, WordSense, archive/delete/restore, or AI study card behavior.

## Scope

- Page A: `/reviews/senses`
  - due sense card is visible.
  - show-answer flow is visible.
  - More menu opens.
  - source fallback dialog works when no chapter position exists.
  - one rating action creates a normal `ReviewLog`.
- Page B: `/senses/review`
  - confirm existing AI-suggested sense.
  - ignore occurrence.
  - reject occurrence.
  - rebind occurrence to an existing confirmed sense.
  - create a new sense from the dialog prefill.

## Data Preparation

Use the Artisan command below with an existing local test user:

```bash
php artisan smoke:sense-review-data --email="<local-test-user>" --marker="<unique-marker>" --json
```

Rules:

- The command requires an existing user and never creates accounts.
- The command does not accept or store passwords.
- Use a unique marker for each replay, for example `codex_sense_smoke_YYYYMMDD_x`.
- The marker should appear in lemmas and example sentences so the real page can be filtered visually.
- Do not clear the database or storage to rerun the smoke. Create a new marker instead.

## Expected Marker Shape

The command prepares:

- one confirmed due WordSense with a due ReviewCard.
- one bound source occurrence for the fallback source dialog.
- one pending occurrence for confirm.
- one pending occurrence for ignore.
- one pending occurrence for reject.
- one pending occurrence for rebind, plus a confirmed candidate sense.
- one pending occurrence for create-new, including raw payload values used to prefill the dialog.

## Real Browser Acceptance

Use MCP Chrome or Chrome DevTools against the local app. Do not replace the page operations with API calls.

1. Log in with the provided local test account.
2. Open `/reviews/senses`.
3. Confirm the marker due card is visible.
4. Click show answer.
5. Open the More menu.
6. Open source context and confirm the fallback source dialog shows the marker example.
7. Apply one visible rating such as Good.
8. Open `/senses/review`.
9. Run the marker occurrence flows: create new, rebind, reject, ignore, confirm.
10. Read back the database state only after the page actions complete, to verify side effects created by the UI.

## Required Readback

Verify these effects after the browser smoke:

- rated ReviewCard has increased `reps`.
- one `ReviewLog` exists for the rated card with `source=sense_review`.
- confirm occurrence is `bound` and has a ReviewCard when auto-FSRS is allowed.
- ignore occurrence is `ignored` and does not create a ReviewCard.
- reject occurrence is `rejected` and does not create a ReviewCard.
- rebind occurrence is `bound` to the candidate sense and has a ReviewCard when auto-FSRS is allowed.
- create-new occurrence is `bound` to the newly created sense and has a ReviewCard when auto-FSRS is allowed.

## Boundaries

This smoke must not:

- modify `.env`.
- use API calls instead of page actions for the acceptance path.
- run destructive database reset commands.
- change FSRS scheduling semantics.
- change delete, archive, restore, or ReviewLog preservation semantics.
- implement AI study card generation.
- store local test account credentials in docs, code, tests, or final reports.
