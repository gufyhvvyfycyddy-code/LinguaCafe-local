# ADR-0056: Machine-Global Testing Database Lease

## Status

Accepted

## Date

2026-08-07

## Context

LinguaCafe uses one shared MySQL testing database from multiple managed Git
worktrees. The former PHPUnit bootstrap locked
`storage/framework/testing/phpunit-db.lock` inside each checkout. Two worktrees
therefore locked two different files while mutating the same database. The
lock also covered only PHPUnit; a long-running testing server or a fixture
command could write the same database without participating.

This caused nondeterministic missing-table, already-existing-table, missing
fixture, and invalid browser-session failures. PID text in a file was not a
sufficient ownership signal because only the operating-system file lock proves
that the owner is still active.

## Decision

Use one versioned, machine-global, exclusive testing database lease for every
process that may write the shared testing database.

### Lease identity

The identity is SHA-256 over four normalized input categories:

1. protocol version;
2. the real Git common directory shared by all worktrees;
3. the normalized `origin` repository identity;
4. a non-secret logical testing database identifier declared in `phpunit.xml`.

The resulting lock path contains only a fixed directory prefix, protocol
version, and the SHA-256 identity. Repository paths, usernames, remote
credentials, and the logical database identifier are not written to the lock
path or metadata.

### Lease location and ownership

The lock lives below a fixed subdirectory of the operating-system temporary
directory. The implementation rejects unsafe files, symbolic links, and
reparse-style directory redirection where it can be detected using link and
real-path checks. Directory or lock failures are fail-closed.

`flock(LOCK_EX)` is the ownership authority. Metadata is diagnostic only and
is written with temporary-file plus atomic rename. It contains only:

- protocol version;
- PID;
- exclusive mode;
- UTC start time;
- a sanitized task label.

A stale metadata file never blocks a new owner and never claims that a lease is
active when the OS lock can be acquired.

### Consumers

- `tests/bootstrap.php` acquires or inherits the lease when `APP_ENV=testing`.
- `tests/Support/run-with-testing-db-lease.php` acquires the lease before
  starting a testing server, testing Artisan command, fixture command, or
  other child process.
- Status inspection is read-only and never releases or overwrites another
  owner's lease.

The runner is fail-fast by default. A caller may request a finite wait with
`--wait-ms`; infinite waiting is not supported.

### Parent and child ownership

A runner child must not deadlock by trying to reacquire its parent's lease.
The parent creates a random, process-scoped proof. The child accepts inherited
ownership only when all of the following agree:

- the requested identity;
- the active OS lock;
- current owner metadata;
- owner PID and start time;
- a proof file containing only a token hash;
- the random token delivered through the child process environment.

A plain environment flag is insufficient. Tokens and full commands are never
written to metadata or logs. Proof files are removed when the parent releases
the lease; a later owner also removes only stale proof files for the same hashed identity after it has acquired the OS lock.

## Command contract

Example:

```text
php tests/Support/run-with-testing-db-lease.php --label=browser-acceptance -- php artisan serve --host=127.0.0.1 --port=88xx
```

Stable runner exit codes:

- `64`: invalid arguments;
- `73`: lease busy;
- `74`: lease unavailable or unsafe;
- `75`: child process could not start or its exit status was unavailable;
- `78`: run mode requested outside `APP_ENV=testing`;
- otherwise: the child process exit code.

## Consequences

### Benefits

- All managed worktrees coordinate one shared testing database.
- Testing servers and PHPUnit cannot mutate the database concurrently when
  both use the required runner/bootstrap.
- Abnormal owner exit releases the OS lock without trusting stale PID text.
- Lock and metadata artifacts contain no database credential or repository
  path.

### Costs and limits

- Every command that writes the testing database must use the runner; commands
  that bypass it remain unsafe and are a process violation.
- Normal child exit, PHP exceptions, shutdown, handled Ctrl+C / Ctrl+Break, and catchable POSIX INT / TERM / HUP / QUIT paths are cleaned up by the runner. A child that ignores graceful termination is force-terminated after a bounded grace period. An uncatchable operating-system kill may
  release the lock before an independently running child is terminated; such
  hard-kill behavior must not be used as the normal stop mechanism.
- `flock` semantics must be provided by the local filesystem. Network filesystems
  with incompatible locking are outside this local-development contract.

## Alternatives considered

### Per-worktree lock files

Rejected because they cannot coordinate a shared database.

### Database advisory locks

Rejected for this layer because acquiring them requires database credentials
and a live database before the safety gate. This lease must be available before
Laravel or MySQL access.

### PID files as ownership

Rejected because stale PID text is not an active lock and PIDs can be reused.

### Automatically instrument every Artisan command

Rejected for this slice because it would modify the product application boot
chain. The explicit runner is smaller, auditable, and reusable without changing
production behavior.

## Validation

- Two fixture worktrees from one Git common repository compute the same identity.
- Different repository or logical database inputs compute different identities.
- Barrier-coordinated multi-process tests prove only one exclusive owner.
- Contenders fail fast, then acquire after release.
- Abnormal owner exit permits recovery despite stale metadata.
- Runner exit-code forwarding and parent-child inheritance are process-tested.
- Existing testing database health checks validate this contract.
- Real MySQL and testing-server acceptance is run only when no other task owns
  the shared testing database.
