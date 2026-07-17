# AI Study Card Service Convergence Browser Acceptance — 2026-07-17

> **Result**: Service convergence and disabled-provider preflight accepted; live provider deliberately deferred
> **Page**: `http://127.0.0.1:8000/chapters/read/5`
> **Viewport**: 1524 × 900

## Accepted behavior

- A real authenticated reader page loaded and the `substantive` token opened the vocabulary sidebar.
- Browser acceptance exposed and then verified the fix for a sidebar positioning regression: the fixed panel moved from outside the viewport to `x=1039`, width `460`, with no document horizontal overflow.
- The pending list showed five chapter items. Dismissing `landscape` changed the tabs from pending 5 / dismissed 0 to pending 4 / dismissed 1; restoring it returned pending 5 / dismissed 0.
- Generating the V6 request package called only `POST /ai-study-card/v6/recommendations/request-package` on the local application. The package reported provider disabled, no provider call, no card creation, no ReviewLog write, no FSRS change, and no WordSense/ReviewCard creation.
- The explicit `调用 V6 AI 推荐（后端预览）` action called only the local `POST /ai-study-card/v6/recommendations/provider-preview` route. With the provider disabled, the UI showed `生成 V6 AI 推荐预览失败。`, re-enabled the action, and did not advance to candidate confirmation or card generation.
- Browser requests contained no provider domain. Console noise was limited to the known local Echo/WebSocket connection failure; no blocking UI exception occurred.

## Database evidence

Global counts before and after the browser flow were identical:

| Record | Before | After |
|---|---:|---:|
| WordSense | 180 | 180 |
| ReviewCard | 103 | 103 |
| legacy `target_type=word` card | 9 | 9 |
| ReviewLog | 168 | 168 |
| AI pending item | 9 | 9 |

Final lifecycle counts were pending 7 / dismissed 0, proving the dismiss/restore round trip returned to baseline.

## Disabled-provider preflight follow-up

- Real Playwright browser interaction on `/chapters/read/14` created/reused one `cities` pending item, opened the existing V6 panel, and clicked `生成 V6 请求包（不调用 AI）`.
- The local request-package route returned 200. The page visibly reported `External request preflight: blocked`, provider/model `disabled / deepseek-chat`, item count 1, timeout and cost ceiling not configured, the exact external-data field list, and `cost_ceiling_not_configured` among the blocking reasons.
- Clicking the explicit local provider-preview action returned the expected 503 fail-closed response. Browser requests contained no external domain.
- Before/after counts were identical: WordSense 180, ReviewCard 103, ReviewLog 168, pending 8.
- Automated evidence: all 79 V6 tests passed with 819 assertions; `npm run development` compiled successfully.

## Product decision

The user explicitly chose to keep the real provider disabled and skip live-provider configuration. No `.env`, key, provider endpoint, or external request was added. A future live-provider task requires new explicit authorization.
