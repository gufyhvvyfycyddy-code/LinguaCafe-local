# LinguaCafe Windows PC Test Feedback Log

> Product-owner hands-on feedback for the Windows executable lane. Record the observed build/commit and the user's words before deciding the implementation. Do not mark an item Closed until a rebuilt PC test version is available for owner retest.

## Status vocabulary

- `NEW` — reported by product owner, not yet reproduced/triaged.
- `CONFIRMED` — reproduced or independently supported by current code/runtime evidence.
- `FIXED_AWAITING_RETEST` — implementation and automated acceptance are complete; product owner has not yet retested the rebuilt executable.
- `CLOSED` — product owner retested or explicitly accepted the result.
- `DEFERRED` — deliberately outside the current PC test objective, with reason recorded.

## Build ledger

| Build | Goal commit | Installed executable | Result |
|---|---|---|---|
| PC-TEST-0.1 | `1fe0fae9d6308118bcf20af7674853d74112aa6a` | `%LOCALAPPDATA%/LinguaCafePCTest/app/LinguaCafe PC Test.exe` + desktop `LinguaCafe PC Test.lnk` | installed + desktop-launch READY + I-04 real main-flow smoke PASS; awaiting product-owner hands-on feedback |

## Feedback

_No product-owner PC runtime feedback has been reported yet. Add each new observation here before changing behavior._
