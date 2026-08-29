# H-07 Public Beta Deployment Runbook

> Status: current deployment procedure for the first public beta
> Updated: 2026-08-29
> Scope: one LinguaCafe application node, private Redis/tokenizer, MySQL 8.4, approximately 100 concurrent users

## 1. Runtime baseline

The supported public-beta runtime is:

- Laravel 13
- PHP 8.4
- MySQL 8.4 LTS
- Apache in the current Web image
- Redis 7.2 on the private Compose network
- the current English tokenizer image with spaCy 3.8, `en_core_web_sm` 3.8.0, and LemmInflect

The first beta remains one application node. H-03 measured the canonical Reading, lookup, and Sense Review application paths as healthy at 100 virtual users. There is no current evidence that requires Nginx/PHP-FPM, Octane, multiple Web nodes, or a new cache topology.

## 2. Required deployment values

The production Compose path fails closed unless the deployment environment provides:

- `APP_KEY`
- `APP_URL`
- `DB_PASSWORD`
- `DB_ROOT_PASSWORD`

Optional database connection overrides include `DB_DATABASE`, `DB_USERNAME`, `DB_HOST`, and `DB_PORT`.

If a reverse proxy, CDN, or load balancer sits in front of LinguaCafe, set `TRUSTED_PROXIES` to the exact trusted proxy IP or CIDR required by that topology. Leave it unset when there is no trusted proxy. Do not use wildcard proxy trust for the ordinary self-hosted deployment.

Do not store real production secrets in repository files or documentation.

## 3. Pre-deploy checks

Before changing a running public instance:

1. Confirm the intended commit and current release notes.
2. Confirm a recent database backup exists and is readable.
3. Render the Compose configuration with the real deployment environment and confirm it resolves without missing-value errors.
4. Build the current checkout's Web and tokenizer images.
5. Confirm MySQL is 8.4-compatible before applying the release.
6. If a proxy is used, confirm its exact source address/CIDR before setting `TRUSTED_PROXIES`.

Do not run a destructive database reset or replace an existing database with a fresh schema.

## 4. Database release step

Container startup deliberately does not run migrations or seeders. Database changes are a separate release action.

For an existing installation, run the normal Laravel migration command manually in an approved release window and answer Laravel's production confirmation interactively:

`php artisan migrate`

Laravel's production confirmation is intentionally retained. Do not automate around it with `--force` in this project workflow.

For a completely new empty installation, run the normal migrations first and then run the default seeder once:

`php artisan db:seed`

The default seeder initializes application settings, dictionary metadata, and font metadata. Routine container restarts and ordinary upgrades do not run the seeder automatically. Run it during an upgrade only when that release explicitly requires seed changes.

## 5. Start and verify

After the database release step:

1. Start or update the Compose services.
2. Confirm the application health endpoint is healthy.
3. Confirm MySQL and Redis are reachable only through the intended network boundary.
4. Confirm the tokenizer can load the English spaCy model and LemmInflect.
5. Open the real Web login page and complete a login.
6. Reload an authenticated page and confirm the session remains valid.
7. Log out through the visible Web flow and confirm return to `/login`.
8. If a reverse proxy is present, verify the application sees the real client IP and HTTPS scheme through that exact trusted proxy; an untrusted source must not be able to spoof `X-Forwarded-For`.

## 6. First-beta capacity and upgrade triggers

The initial topology is deliberately small. Increase capacity only when current measurements show a need.

Consider a larger or dedicated-CPU application node when sustained CPU pressure, H-01/H-03 latency evidence, or real public traffic shows that the shared 2-vCPU node is the bottleneck. Add a managed-database standby when database downtime becomes unacceptable for the beta. Do not add a second Web node until shared media/storage and shared session/cache behavior are deliberately designed and tested.

## 7. Rollback boundary

If a release fails before database migration, return to the previous application image/commit.

If a database-changing release fails after migration, use the existing tested backup/restore procedure and its write fence / safety rollback path. Do not improvise destructive schema resets. H-04 and H-07 both proved the restore path against MySQL 8.4 in isolated testing.
