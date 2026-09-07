# Public Security Review

Updated: 2026-09-07

## Status

**Incomplete for real-user release.** Public review can continue. Current CodeQL findings are closed and dependency Highs are explicitly dispositioned; release still requires environment-hygiene remediation plus the remaining platform/operations gates.

## Current-tree path audit

The public source tree currently tracks three environment-configuration paths: the main environment file, one historical backup-named environment file, and the testing environment file.

Project rules prohibit reading or modifying those files in this task. Their contents were not inspected or reproduced. Their tracked presence is itself a publication-hygiene blocker and is already represented in the architecture review repository.

No tracked database dump, private-key file, SQLite database, cookie store or session-data file was identified by the filename audit.

## Current-tree content scan

A read-only high-signal credential-pattern scan was run across eligible tracked content while excluding the prohibited environment files.

Result: no match for the checked common cloud credential, GitHub token, private-key header, AI-service key, Google API key or Slack-token patterns.

This result applies only to the scanned content. It does not prove the excluded environment files or all historical commits are clean.

## History boundary

Environment-file history was not content-scanned because project rules forbid reading that material in this task. No claim is made that historical exposure has been ruled out. If any value was ever a real credential, rotation or revocation should happen before a history-cleaning decision.

## Repository hygiene already visible on current master

Current default branch no longer tracks the historical Playwright CLI logs/page dumps, tokenizer Python bytecode, or the unused experimental `resources/vue3` prototype. Restricted workflow token permissions and the public security-reporting policy are present on current master.

Current default-branch security status after source PR #40:
- CodeQL open alerts: 0;
- Dependabot: 28 open total — 0 Critical / 6 High / 18 Medium / 4 Low;
- the six High alerts have explicit current reachability/accepted-risk/upgrade-gate dispositions in the architecture review repository.

## Remaining security work

- environment-file remediation and any required credential rotation;
- authorized environment-file history review where permitted;
- medium/low dependency maintenance plus planned Laravel 12 and Vue3/Vuetify3 modernization;
- Android/iOS release-security evidence;
- production hosting, HTTPS, backup, monitoring, privacy/support and account-deletion evidence.

Detailed owners and acceptance criteria live in the architecture and product-launch issue trackers.
