# Public Release Checklist

## Repository authority

- [x] One current source-of-truth repository is named.
- [x] Architecture and product-launch review repositories exist and are public.
- [x] Architecture/product status pages are refreshed for source PR #30/#31/#32/#34 and closed issues #25/#26 (architecture PR #30; product PR #17).
- [x] Historical `LinguaCafe-dev-main` is visibly marked as historical on GitHub (historical-repo PR #1).
- [x] Exact duplicate source Issues #12–#18 are closed as duplicates while canonical Issues #5–#11 remain open.

## Worktree safety

- [x] Protected local checkout inventoried.
- [x] Local/remote divergence measured.
- [x] Publication audit moved to an isolated clean remote-head worktree.
- [x] No reset, clean, stash or bulk staging was used on the protected checkout.

## Public security

- [x] Current tracked paths checked for obvious database/private-key/session artifacts.
- [x] High-signal credential scan run on eligible tracked content.
- [x] Historical browser automation artifacts and tokenizer bytecode are absent from current master.
- [x] SECURITY.md exists.
- [ ] Tracked private runtime-configuration paths are remediated under explicit authorization.
- [ ] Any historically valid exposed credential is rotated/revoked.
- [ ] Authorized history review/remediation is completed.
- [ ] Remaining dependency and CodeQL launch blockers are triaged.

## Platform evidence

- [x] Frozen Web/PC functional evidence and current source head are distinguished.
- [ ] Current signed Android release/AAB/Play evidence is complete.
- [ ] macOS/Xcode/signing/device/TestFlight/App Store evidence is complete.

## Real-user launch

- [ ] Production hosting, HTTPS, database, mail, backup and monitoring runbook is exercised.
- [ ] Privacy, support and account-deletion paths are public and tested.
- [ ] First 10 deep-use users complete the defined validation loop.
- [ ] Retention and acquisition evidence is collected before pricing/investor conclusions are frozen.
