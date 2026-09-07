# Repository Authority

## Current authority

`gufyhvvyfycyddy-code/LinguaCafe-local` is the only current source-of-truth repository. It contains the shared backend, Web/PC client, Android project, iOS project, tests, ADRs and release evidence.

## Review repositories

`LinguaCafe-architecture-review` contains public review findings, technical debt, platform maturity, security evidence and review questions.

`LinguaCafe-product-launch` contains product direction, hosting, stores, privacy, support, growth, pricing and investor-readiness work.

These repositories do not replace the source tree and must not contain a second competing copy of current application code.

## Historical repository

`LinguaCafe-dev-main` is a historical development line. It is not authoritative for current code, product status or external review.

## Evidence rule

Current source code and current tests outrank old reports. A frozen browser-acceptance baseline may remain useful evidence, but later source changes must be described separately unless they actually received the same acceptance.
