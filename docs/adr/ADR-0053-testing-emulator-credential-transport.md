# ADR-0053 — Testing Emulator Credential Transport

## Status

Accepted under current M0–M18 goal authorization

## Context

ADR-0034 requires a random, memory-only password to be entered through a normal
UI and forbids putting it in repository files, command literals, logs, screenshots
or browser password storage. That remains the default. A local Android emulator,
however, may expose a real rendered UI through ADB while rejecting Windows
SendInput/Computer Use activation. Treating that provider limitation as a product
blocker prevents device acceptance without protecting any real account.

## Decision

For a task-only identity in the server-bound testing database, an Android emulator
may receive its random password through a scoped, non-logging ADB input bridge when
all of these conditions hold:

1. official Computer Use was attempted and cannot target the emulator keyboard;
2. the password is generated inside one ephemeral process after testing sentinel
   verification and is never printed, returned or written to a file;
3. no full password literal appears in the command source or a process argument;
   the bridge emits ordinary UI key events one character at a time;
4. fixture creation may use the testing harness, but every behavior claimed as
   device acceptance is performed through rendered application controls;
5. the identity is least-privilege, task-marked and local-only; the password is
   cleared immediately after login and the identity/token/fixtures are deleted at
   batch closeout.

This exception does not authorize existing credentials, development/production
data, remote devices, password persistence, authentication bypass, direct API
writes presented as UI evidence, or disclosure through screenshots/logs.

## Verification

The acceptance report must record sentinel binding, the failed Computer Use input
channel, the ADB device identity, rendered UI actions, credential non-disclosure,
exact cleanup and zero remaining task rows.
