# Reader Hotkey Policy — Browser Acceptance

Date: 2026-07-23

Status: **Accepted / Production Closed**

Scope: Phase 6G, one Reader responsibility only

## Delivered boundary

`ReaderHotkeyPolicy.js` now owns the pure mapping from established keyboard/context facts to one plain effect intent: suppression, legacy key codes, stage values, Shift parameters, and prevent-default decisions.

`TextBlockGroup.vue` still owns listener registration, editable-target and active-Vuetify-surface inspection, `preventDefault`, text to speech, stage writes, scrolling, Anki, selection navigation, hover closing, Vue events, and every other effect.

No shortcut, dialog, visible copy, gesture, endpoint, payload, Vuex/store, tokenizer, surface/lemma, WordSense, ReviewCard, ReviewLog, FSRS, source-context, AI, or non-English behavior changed.

## Automated evidence

- Focused hotkey policy and source-integration suite: 11/11 passed.
- Combined Reader Node loop: 72/72 passed.
- `TestingDatabaseHealthTest`: 6 tests / 47 assertions passed.
- `TestingDatabaseHealthConfigTest`: 6 / 50 passed.
- `ReaderFsrsHighlightTest`: 28 / 132 passed.
- `TextBlockPhraseIndexingTest`: 5 / 18 passed.
- `npm run development`: compiled successfully in 13.75 seconds; only existing Sass deprecation notices remained.
- `git diff --check`: passed.

The focused suite was observed RED for the missing module. Ten pure behavior cases then passed while the component integration guard remained RED; after the effect adapter change all 11 passed.

## Authenticated official-browser evidence

An isolated testing-MySQL fixture and the official OpenAI Chrome connection were used. The batch recorded pre-existing user tabs, created one automation-owned tab, reset the viewport, and closed only that tab.

At 1920×1000:

- a real click selected `beta`;
- a real `ArrowLeft` keypress selected `alpha`;
- a real `ArrowRight` keypress returned selection to `beta`;
- opening `添加新释义` focused the dictionary input;
- pressing `ArrowRight` inside that input kept the Reader selection on `beta`, kept focus and value `beta`, and did not trigger Reader navigation;
- horizontal overflow remained zero and `完成阅读` remained available.

At 900×900:

- a real click selected `beta`;
- `ArrowLeft` selected `alpha`;
- focusing the narrow `词典搜索` input and pressing `ArrowRight` kept the Reader selection on `alpha`;
- horizontal overflow remained zero and `完成阅读` remained available.

Console output contained the existing local settings request 500; no hotkey-policy, selection, Reader, or Vue exception appeared. Provider telemetry warnings belonged to the official browser runtime, not the application.

## Protected-write proof

Before cleanup, both user-level and global snapshots remained:

| Table | User | Global |
|---|---:|---:|
| WordSense | 0 | 0 |
| ReviewCard | 0 | 0 |
| ReviewLog | 0 | 0 |

The fixture was cleaned twice with `remaining_users=0` both times. No scoring, scheduling, lifecycle, AI-provider, migration, or production-database write was introduced.

## Quantified architecture result

- `TextBlockGroup.vue`: 2,163 → 2,099 lines.
- Phase 6A–6G: 2,514 → 2,099 lines.
- The component replaced an inline 135-line suppression/mapping table with one intent resolver and an explicit effect adapter.
- The policy is 90 lines and has ten behavior tests plus one integration guard.

The five-axis scoped review found no required changes: all legacy key codes and effect parameters are characterized, the component keeps all capabilities, the policy is deterministic and input-immutable, no dependency or public contract was added, and runtime work remains constant-time.

Phase 6G is closed. This does not close Phase 6; the next structural slice must move only one characterized Reader responsibility.
