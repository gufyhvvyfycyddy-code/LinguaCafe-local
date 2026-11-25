# ADR-0067 — Windows PC Test current-user protection for saved administrator state

Status: **Candidate, not deployed** (2026-11-25; I05 review branch only).
Scope: historical PC Test candidate `a6cdc04faed3598e66d5b8fa7b202e98a8600179`, not GitHub master or the installed user's state.

## Problem and owner

`PcTestState.AdminPassword` was a regular JSON property; `RuntimeManager.SaveState` wrote the whole object directly to the user-owned `D:\AI-Tools\LinguaCafe\PC-Test\state.json`. A privileged PC-test administrator password at rest is unnecessary exposure. The Windows desktop runtime owns its own bootstrap/login credential, not LinguaCafe's WordSense/FSRS tables, CalDAV or Super Productivity profile.

## Frozen candidate seam

- Keep the existing `MainWindow` setup/login and the in-memory `AdminPassword` interface unchanged. Never introduce an HTTP auto-login bypass, a new credential database, a second FSRS owner, or new remote/network access.
- Serialize `AdminPassword` with `[JsonIgnore]` and persist only `AdminPasswordProtected` as a Base64-encoded Windows DPAPI `CurrentUser` blob with fixed purpose binding. Current-user DPAPI is an **at-rest** boundary, not protection against other processes executing as the same Windows account or malware on that account.
- On an existing legacy state, read the plaintext field only from the explicitly selected state file, encrypt with the current Windows user and replace that file atomically using a same-directory ciphertext staging file. Never create a plaintext backup, log credential material, echo a password, or silently provision a second administrator on failure. A failed encryption/replacement must leave the existing file intact and halt login.
- A corrupt encrypted blob, a missing/empty credential or another Windows-user context **fails closed**. Do not fall back to a plaintext property when an encrypted field exists. Treat a future unsupported state schema version as unknown and stop.
- Versioned ZIP, Docker images, old installed app and user state remain untouched until authentic isolation/GUI tests and a roll-forward/rollback strategy are accepted.

## Explicit compatibility and release HOLD

Pre-migration PC Test binaries expect the old JSON `AdminPassword` field. An encrypted-only v2 state cannot automatically be read by the old binary, **so rolling back just the executable is not sufficient**. Do not deploy this review candidate to the real D-drive installation or reuse real state before an independently rehearsed local-user account-preserving rollback/migration plan. Copying an old plaintext backup into a public package, log or staging folder is prohibited. Even after atomic replacement the underlying filesystem may retain recoverable old blocks: do not claim forensic secure erasure.

## Scope and acceptance

Allowed candidate files: `desktop/windows-pc-test/{PcTestState.cs,PcTestStatePersistence.cs,RuntimeManager.cs,LinguaCafe.PcTest.csproj}`, a synthetic Windows-only regression smoke and its exact generated-file ignores, this ADR. Prohibited: real `state.json`, `.env*`, installed shortcut/app/volumes, other databases, original dirty checkout, `MainWindow` login rewrite, public release, master push.

Required synthetic checks: new/current-user roundtrip; real legacy JSON migration without plaintext in output; atomic replacement; rejection of empty, missing and corrupt secure state without downgrade; source-build and existing Windows-PC packaging guard; no test secret output. Required **future** release checks: same logged-on user restart, other-user failure, crash/failure restore, old binary rollback across migration, local state ACL, credentials not in logs/ZIP/images; server-bound `APP_ENV=testing` + exact testing DB sentinel before all authentic browser writes. No feature or UI ACCEPT based on unit tests alone.

References: Microsoft Windows password handling and DPAPI documentation:
https://learn.microsoft.com/en-us/windows/win32/secbp/handling-passwords
https://learn.microsoft.com/en-us/dotnet/api/system.security.cryptography.protecteddata.protect
