# ADR-0066: H-07 public runtime, trusted proxy, and single-node deployment gate

- Status: Accepted under current goal authorization; final H-07 acceptance pending verification
- Date: 2026-08-29
- Scope: public-beta runtime and deployment boundary for approximately 100 concurrent users

## Context

H-03 measured the canonical Reading, dictionary lookup, and Sense Review flows as healthy at 100 virtual users. The mixed tail latency came from fresh Apache prefork cold-burst admission rather than from the learning-domain queries, FSRS, sessions, or MySQL. H-06 then added a source-IP login limiter, which is only correct behind a reverse proxy when Laravel can distinguish the trusted proxy from the real client.

The repository subsequently moved through Laravel 12 and now targets Laravel 13. Laravel 12 stopped receiving bug fixes on 2026-08-13 and remains on security-only maintenance until 2027-02-24. Laravel 13 receives bug fixes through Q3 2027 and security fixes through 2028-03-17. PHP 8.2 reaches end of security support on 2026-12-31, while PHP 8.4 remains in active support through 2026-12-31 and security support through 2028-12-31. MySQL 8.0 reached EOL in April 2026; Oracle recommends MySQL 8.4 LTS, whose Premier Support runs through April 2029.

The existing production Docker path also had four public-deployment defects:

1. PHP images still used PHP 8.2 while Composer now requires PHP 8.4.
2. the tokenizer imports `lemminflect`, but the Python Dockerfiles did not install it;
3. the default Compose file ran upstream GHCR Web/Python images instead of the current LinguaCafe checkout, exposed Redis on the host, enabled the MySQL general log, and mounted two supervisor files that do not exist in the repository;
4. the production entrypoint automatically ran migration and seeding with `--force` on every container start.

FSRS may legitimately schedule cards beyond the 2038 MySQL `TIMESTAMP` ceiling. The current testing regression includes a 2099 due date, so `review_cards.fsrs_due_at` and the corresponding ReviewLog before/after due timestamps require `DATETIME` storage.

## Decision

1. Public beta standardizes on Laravel `^13.0`, PHP `^8.4`, MySQL `8.4` LTS, and PHPUnit `^12.0`.
2. The Laravel 13 upgrade follows the official skeleton security changes already proven by regression:
   - CSRF references use `PreventRequestForgery`;
   - cache `serializable_classes` defaults to `false`;
   - session serialization uses `json`.
3. Switching session serialization to JSON is accepted before public beta. It may invalidate sessions created before the upgrade, so the deployment must be treated as a one-time re-authentication boundary. LinguaCafe does not rely on storing arbitrary PHP objects in session state.
4. Apache remains the Web runtime for the 100-user beta. H-03 does not justify introducing Nginx/PHP-FPM, Octane, a second application node, query caches, or a new worker topology.
5. Reverse-proxy trust uses Laravel's documented `Middleware::trustProxies(...)` seam and one optional deployment variable, `TRUSTED_PROXIES`.
   - no value means forwarded client headers remain untrusted;
   - self-hosted reverse proxies should name their exact proxy IP/CIDR, commonly loopback for a same-host proxy;
   - wildcard trust is not the self-hosted default;
   - cloud-specific wildcard/header handling is a deployment decision and must follow the provider's documented topology.
6. The default production Compose path builds the current checkout with `docker/PhpDockerfile` and `docker/PythonDockerfile`; it no longer pulls the upstream LinguaCafe application/tokenizer images.
7. Redis remains private to the Compose network. MySQL general-query logging is disabled in the production Compose path. The Web container is given the internal Redis and tokenizer host names.
8. Production Compose fails closed when `APP_KEY`, `APP_URL`, `DB_PASSWORD`, or `DB_ROOT_PASSWORD` is absent. No real secret is stored in the repository.
9. Container restart does not mutate the database. Production migration is a separate explicitly controlled release action; production container startup neither migrates nor seeds. Development startup keeps its existing migration/seed behavior but no longer uses `--force`.
10. FSRS due/log due timestamps use MySQL `DATETIME` for values beyond 2038. The rollback migration refuses to narrow columns when out-of-range data exists rather than truncating or corrupting schedule history.
11. The first public beta remains a single application node. Redis and the tokenizer may run privately on that node while the database is separated. Multi-node Web availability is deferred until shared media/storage and shared cache/session topology are deliberately designed and verified.

## Consequences

- H-06 per-IP login limiting can rely on the real client IP after the deployment explicitly trusts its proxy chain.
- The default repository container build now represents the current LinguaCafe code and tokenizer behavior.
- Public startup cannot silently run with example database passwords or an absent Laravel application key.
- Existing users may need to sign in once after the Laravel 13 session-serialization cutover.
- The 100-user beta keeps the already measured Apache path and avoids an unsupported infrastructure rewrite.
- A future multi-node architecture remains a separate milestone; buying a load balancer before shared state is ready would not create correct high availability.

## Current external references

Checked 2026-08-29:

- Laravel 13 release/support policy: https://laravel.com/docs/13.x/releases
- Laravel 13 upgrade guide: https://laravel.com/docs/13.x/upgrade
- Laravel trusted proxies: https://laravel.com/docs/13.x/requests#configuring-trusted-proxies
- PHP supported versions: https://www.php.net/supported-versions.php
- PHP current releases: https://www.php.net/
- MySQL 8.0 EOL notice: https://dev.mysql.com/doc/relnotes/mysql/8.0/en/
- MySQL 8.4 LTS release model: https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html
- AWS Lightsail instance/database/snapshot pricing: https://aws.amazon.com/lightsail/pricing/ and AWS Lightsail documentation
- Laravel Cloud pricing: https://cloud.laravel.com/pricing

## Verification contract

H-07 closes only when all of the following are true:

- Composer lock is valid and has no known security advisory;
- Laravel 13 runs under PHP 8.4 and the focused upgrade/FSRS/concurrency/auth suites pass;
- a request from an untrusted address cannot spoof `X-Forwarded-For`, while an explicitly trusted proxy resolves the intended client IP and HTTPS scheme;
- production Compose validates with dummy external secrets and fails if required secrets are absent;
- current-source Web and tokenizer images build successfully, PHP reports 8.4, and tokenizer can import both spaCy and LemmInflect;
- no implicit production migration/seed or public Redis port remains in the default production path;
- the H-07 current-price cost model is recorded with an access date and clear assumptions;
- repository diff, tests, build, and cleanup are green before milestone closure.
