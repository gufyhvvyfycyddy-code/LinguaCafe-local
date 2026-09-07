# Security Policy

## Supported review target

Security reports for this public project should be checked against the current `master` branch.

Frozen functional external-review baseline:
`6989ed27c933716f9069bb9b14fba92624081fc4`

Publication/security review is synchronized through source commit:
`2d8f062fcc453fa609abbed228df371dd192ec57`

The frozen functional baseline and the later security/dependency fixes are intentionally reported separately.

## Reporting a vulnerability

Do not post credentials, tokens, cookies, personal data, database content, or exploit details in a public Issue.

Use GitHub Private Vulnerability Reporting:

1. Open this repository.
2. Open **Security**.
3. Open **Advisories**.
4. Choose **Report a vulnerability**.

Include:
- affected commit/version;
- affected path or feature;
- reproduction steps;
- expected impact;
- whether real user data or credentials may be involved;
- the smallest safe proof needed to reproduce.

## Public Issues

Use public Issues for non-sensitive bugs, architecture problems, UI/UX problems and release-readiness work.

Do not move sensitive details from a private report into a public Issue until the affected secret/data has been remediated and disclosure is safe.

## Known publication hygiene work

The public-review program tracks repository-hygiene work separately in:
https://github.com/gufyhvvyfycyddy-code/LinguaCafe-architecture-review/issues/1

That Issue intentionally does not reproduce secret values.

## Upstream

LinguaCafe is derived from:
https://github.com/simjanos-dev/LinguaCafe

If a vulnerability is proven to exist unchanged in upstream code, coordinate responsible disclosure rather than assuming the local fork is the only affected project.
