# Reader attention and facade browser acceptance — 2026-07-17

Status: **Accepted / Production Closed**

## Scope

Authenticated local acceptance on `/chapters/read/5`, using a headed Playwright browser and the repository's local acceptance account rule.

## Results

| Scenario | Evidence | Result |
|---|---|---|
| Wide reader before selection | 1524px viewport; reader right padding `0px`; content width 1190px; no horizontal overflow | PASS |
| Active sidebar | Selecting `technology` displayed the exact word panel; right padding became `484px`; content width became 706px; no horizontal overflow | PASS |
| Selection cleared | Real Cancel Selection action restored right padding to `0px` and content width to 1190px | PASS |
| Dependent hover settings | Turning off Hover Vocabulary hid Automatic Lookup, Hover Delay, and Preferred Position; turning it back on restored them | PASS |
| Narrow fallback | 900px viewport retained `0px` right padding, 822px content width, and no horizontal overflow | PASS |

Console output contained only the pre-existing local Echo WebSocket connection failures for port 6001. No Reader Vue, request, or layout error was introduced.

## Architecture evidence

`TextBlockService` delegated its existing public facade methods to the constructor-owned `ReaderDataService` and removed unreachable duplicates. No database write or migration was part of this slice.
