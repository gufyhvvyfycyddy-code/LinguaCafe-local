# H-07 Public Runtime and Cost Acceptance — 2026-08-29

## Verdict

**Accepted / DONE.**

H-07 closes the public-beta runtime, trusted-proxy, deployment-container, MySQL-version, far-future FSRS date, and current-cost gates. The accepted product/test commit is:

`bf8de9339b97dfc889ca13ee6706e90bfd317108` — `feat: close H-07 public runtime gate`

The first public beta keeps one application node. H-03 already showed that Reading, lookup, and Sense Review application flows are healthy at 100 virtual users, so H-07 does not introduce a second Web node, Nginx/PHP-FPM, Octane, a new cache layer, or a new scheduling architecture.

## Supported runtime and deployment boundary

The accepted runtime is Laravel 13 on PHP 8.4 with MySQL 8.4 LTS. The default production Compose path now builds the current repository checkout instead of pulling upstream LinguaCafe application/tokenizer images.

The production path also now:

- requires `APP_KEY`, `APP_URL`, `DB_PASSWORD`, and `DB_ROOT_PASSWORD` instead of accepting example secrets;
- keeps Redis private to the Compose network;
- disables the production MySQL general query log;
- gives the Web container the internal Redis and tokenizer service names;
- installs the tokenizer dependency it actually imports, LemmInflect;
- limits the tokenizer image to the current English-only spaCy runtime and fixed `en_core_web_sm` 3.8.0 model;
- removes redundant PHP extension compilation from the PHP 8.4 image;
- does not mutate or seed the production database during an ordinary container restart.

Production database changes are now an explicit release step. Laravel's ordinary `php artisan migrate` production confirmation remains in place; the project does not automate around that confirmation. A completely new empty installation additionally runs the existing default seeder once because it creates required application settings, dictionary metadata, and font metadata. Routine upgrades and restarts do not seed automatically. The operational procedure is recorded in `docs/release/h07-public-beta-deployment-runbook.md`.

## Trusted proxy gate

Laravel's documented `Middleware::trustProxies(...)` seam is used through one optional deployment value, `TRUSTED_PROXIES`.

Fresh HTTP regression proved both sides of the boundary:

- with no explicit trusted proxy, a request from `10.0.0.20` cannot replace its source address or scheme by sending forged `X-Forwarded-For` / `X-Forwarded-Proto` headers;
- with `10.0.0.10` explicitly trusted, the same forwarded headers correctly resolve the intended client IP and HTTPS scheme.

This closes the H-06 handoff requirement: the public login IP limiter may rely on `$request->ip()` only after the real deployment explicitly names its trusted proxy chain.

## FSRS date range and MySQL 8.4

MySQL `TIMESTAMP` cannot represent the long scheduling horizons FSRS can legitimately produce. H-07 therefore moves `review_cards.fsrs_due_at`, `review_logs.previous_due_at`, and `review_logs.new_due_at` to `DATETIME`.

The migration performs the change in a UTC database session. Its rollback refuses to narrow the columns if any stored due date lies outside the MySQL `TIMESTAMP` range; it does not truncate or silently corrupt review history.

A real MySQL regression persisted and reloaded a due date of `2099-01-01 00:00:00` on both ReviewCard and ReviewLog fields.

The Compose paths used by production, Windows development, macOS development, H-02 testing, and H-04 restore testing now use MySQL 8.4. The MySQL healthchecks also use container-scoped password expansion instead of accidentally expanding `$MYSQL_ROOT_PASSWORD` on the host.

## Real MySQL 8.4 restore evidence

H-04's existing restore owner was rerun against MySQL 8.4 in an isolated testing environment.

Successful drill:

- created a real backup;
- mutated data after backup;
- submitted the existing Redis-backed restore operation;
- activated the write fence;
- created the safety snapshot;
- restored the target backup;
- ended with operation state `succeeded`;
- restored the exact expected data;
- left zero validation-database residue.

Failure/rollback drill:

- deliberately made the target restore fail after the safety boundary;
- ended with operation state `rolled_back`;
- automatically restored the pre-restore safety snapshot;
- preserved the expected pre-restore mutation;
- released the write fence and maintenance state;
- left zero validation-database residue.

No development or production database was reset, restored, migrated, or deleted during these drills.

## Automated verification

The larger H-07/Laravel 13 changed-file regression completed with:

- **496 passed / 2091 assertions / 2 skipped**.

A later focused final gate, after code review findings were addressed, completed with:

- **77 passed / 398 assertions** under PHP 8.4.24.

That focused gate includes:

- H-07 trusted-proxy behavior;
- H-07 public runtime contract;
- far-future FSRS dates;
- ReviewCard semantic snapshot matching;
- same-user and different-user ReviewSettings initialization concurrency.

The same-user regression launches eight concurrent resolver processes for one account and proves that exactly one Default preset and one English binding remain. This directly covers the MySQL unique-key race handled by the Laravel 13 compatibility patch.

Additional gates:

- H-06 authentication/runtime regression: **31 tests / 213 assertions**;
- `composer validate`: PASS;
- `composer audit`: **no known security vulnerability advisories**;
- `git diff --check`: PASS before product commit;
- root `npm run development`: PASS;
- production Compose with required deployment values: PASS;
- production Compose with missing required secrets: fail-closed, exit code 1;
- current-source Web image: built successfully, PHP 8.4 / Laravel 13 runtime verified;
- final tokenizer image: built successfully and reported spaCy `3.8.16`, model `3.8.0`, and LemmInflect output `banks → bank`.

## Real browser acceptance

A real browser server was bound to the existing Lane 04 testing database on port 8874. Before authentication writes, the server-bound acceptance sentinel proved:

- `environment=testing`;
- `database_is_testing=true`;
- `sentinel_present=true`.

The normal `/setup` UI created the task-provided local testing administrator because the isolated testing database contained no user. The repository report does not record the concrete password.

The real Web flow then proved:

1. the visible `/login` form accepted the testing email/password;
2. a real Playwright `fill + click` produced the normal login request and reached the authenticated `/` page;
3. a real page reload remained authenticated, proving the Laravel 13 session/cookie path;
4. after login, the local Chrome DevTools input channel exhibited a current upstream tool defect in which pointer commands may report success while emitting zero DOM pointer/click events;
5. following the upstream fresh-page workaround, a new page was created inside the same authenticated Playwright browser context;
6. on that fresh page, a real pointer click on `退出登录` opened the visible confirmation dialog;
7. a second real pointer click on the visible `退出` confirmation completed logout and returned the browser to `/login`.

The browser acceptance therefore uses real DOM user events and observable navigation. API success was not substituted for the login/logout flow.

## Production-tool incidents repaired

H-07 found and repaired production-essential tooling/runtime defects rather than hiding them behind expected behavior:

- Docker Desktop initially failed Docker Hub authentication through its network path. The existing working host proxy path was verified and Docker build traffic was sent through Docker Desktop's documented build proxy mechanism without hard-coding a machine-specific proxy address into the repository.
- The production tokenizer Docker build previously depended on repeated live `spacy download` calls and downloaded many languages outside the current English-only product boundary. It now installs one fixed English model wheel and the missing LemmInflect dependency.
- The local Playwright wrapper could successfully create Chrome yet remain attached to the DevSpace Windows job tree and appear to time out. The local wrapper was repaired to launch its browser process outside that job ownership and to use a dedicated automation profile.
- The dedicated browser profile disables Chrome password-manager state that can interfere with automated acceptance. This does not claim to fix the upstream post-login CDP input defect; the accepted workaround for that upstream issue is a fresh page in the same authenticated context plus observable outcome verification.
- OpenCode DeepSeek Flash free review failed with a provider server error. The free Mimo fallback completed a read-only review. Its useful finding about missing same-user preset-race coverage was converted into a permanent regression; intentional fail-closed deployment changes were not treated as product regressions merely because the reviewer labeled them Critical.

No notification script or DCP was used.

## Current cost model — checked 2026-08-29

Prices below are current provider list prices retrieved from official provider pages on 2026-08-29. Taxes, exchange rate, domain charges, outbound overages, optional snapshots, and future provider changes are not included in the exact USD base totals.

### Recommended first-beta shape: DigitalOcean

DigitalOcean Basic Regular Droplet:

- 4 GiB RAM / 2 vCPU / 80 GiB SSD / 4 TiB transfer: **$24/month**.

DigitalOcean Managed MySQL Basic Regular:

- 2 GiB RAM / 1 vCPU / 30–60 GiB storage range: **$30.45/month** for the primary node.

Minimum first-beta base:

- app node $24 + single managed MySQL node $30.45 = **$54.45/month**.

The single database node is acceptable for a limited beta where short database downtime is tolerable and the tested backup/restore path remains the recovery owner. For a beta that requires database failover, add one matching standby node; the base becomes approximately **$84.90/month** before optional extras.

For budgeting rather than invoice prediction, reserve approximately **¥600–800/month** for the minimal public beta and **¥800–1000/month** when adding the matching database standby. These envelopes deliberately leave room for exchange-rate movement, tax, domain/snapshot/storage overhead, and small usage variance. They are not claimed as an exact USD/CNY conversion.

Current sources:

- https://www.digitalocean.com/pricing/droplets
- https://www.digitalocean.com/pricing/managed-databases
- https://docs.digitalocean.com/products/databases/mysql/details/pricing/

### AWS Lightsail comparison

The comparable Lightsail Linux bundle is also **$24/month** for 4 GiB / 2 vCPU / 80 GB. Lightsail's own documentation states that this $24 dual-stack plan has a **20% CPU baseline** and relies on accumulated burst capacity above that baseline.

Because H-03's observed deployment issue was specifically a cold-burst admission problem, that CPU model gives no current reason to prefer the Lightsail $24 instance over the DigitalOcean first-beta shape. Lightsail remains viable if later operational constraints make it preferable; H-07 does not migrate providers merely to create another deployment path.

Current sources:

- https://aws.amazon.com/lightsail/pricing/
- https://docs.aws.amazon.com/lightsail/latest/userguide/baseline-cpu-performance.html

### Laravel Cloud comparison

Laravel Cloud currently lists:

- Starter: **$5/month plus usage**, including $5 monthly usage credit;
- Growth: **$20/month plus usage**;
- Flex 2 GiB compute monthly cap: **$24**;
- Pro 4 GiB compute monthly cap: **$32**.

Laravel Cloud reduces server-management work, but selecting it now would also require deliberate treatment of the current MySQL, Redis, Python tokenizer, persistent storage, backup/restore, and current Compose topology. H-07 has no evidence that this platform migration is necessary for the first public beta, so Laravel Cloud remains a later operational option rather than a new runtime path.

Current source:

- https://laravel.com/cloud/pricing

## Cleanup proof

After real browser work:

- the H-07 testing PHP server was stopped;
- port 8874 had no active listener;
- TestingDatabaseLease reported `active=false` and `stale_metadata=false`;
- the Playwright automation session was closed;
- the temporary local browser auth-state file was overwritten and no credential was added to the repository;
- H-04 restore containers and temporary validation databases had already been removed by their drill cleanup.

No `.env` file was read or modified. No `AGENTS.md` change, `.omo` action, destructive database reset/wipe, notification script, DCP, or force push occurred.

## H-08 handoff boundary

H-07 is closed. H-08 owns the public package/content-rights gate.

H-08 must verify that anything included in a public package is actually redistributable or explicitly authorized. A user's ability to import or privately use a text does not establish a right to redistribute that text in a public LinguaCafe bundle.
