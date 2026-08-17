# LinguaCafe Phase G G-05 Zero-Delete Closure — 2026-08-18

Status: G-05 final acceptance — zero-delete closure

Final accepted baseline: `829a8e236534c8769a29638c4e6189a94e77837a`

## Decision

G-05 closes with **zero production deletions**.

The current G-04 classification authorizes exactly `0` `DELETE_CANDIDATE` items. All 11 advanced capability families still retain current product, shared-owner, data, compatibility, safety, recovery, or diagnostic value. G-05 therefore has no authorized orphan to remove.

No production feature, route, service, component, configuration, test, or documentation contract was deleted to manufacture cleanup work.

## Predecessor evidence

The three independent G-05 predecessor gates are canonical, reported, verified, and blocker-free on the current product-equivalent baseline:

1. `G05_ZERO_DELETE_ELIGIBILITY_REVALIDATED`
   - all 11 families revalidated;
   - authorized delete candidates: `0`;
   - reclassification-required items: `0`.
2. `G05_COMPAT_PROTECTION_AUDIT_READY`
   - all three compatibility slices remain protected;
   - improper delete candidates: `0`.
3. `G05_NOOP_REGRESSION_SMOKE_PASSED`
   - `npm run development` passed;
   - compact automated anchors passed `40/40`;
   - real desktop browser smoke passed;
   - ordinary navigation remains centered on 阅读 / 复习 / 生词 / 我的;
   - retained advanced capabilities remain reachable through 我的 → 高级 and their contextual surfaces;
   - testing resources were cleaned and the final testing DB lease was inactive with no stale metadata.

The regression window used the clean product-code baseline `77dd62f08624984ff64385cee9f606de6719da0c`. Fresh Git verification confirms that the committed delta from that baseline through G-04 HEAD `829a8e...` contains only the Goal ledger and the G-04 classification document, so the tested product code is equivalent to current committed product code.

## Protected compatibility slices

The following three narrow `COMPAT_READ_ONLY` responsibilities remain retained:

1. Legacy Custom Study 1A preview-token route/component/API compatibility required by ADR-0040 and the existing compatibility guards.
2. Saved Search schema V1 readability required by ADR-0038; existing V1 rows remain readable and upgrade when edited.
3. The inert `backup.restore_preview_ttl_seconds` compatibility placeholder retained by ADR-0055 until a later authorized configuration-cleanup contract supersedes that retention.

These items are compatibility obligations and are not G-05 deletion candidates.

## Zero-delete acceptance

G-05's rule is to delete only items already proven and authorized as orphaned by G-04. With an authorized deletion inventory of zero, deleting nothing is the correct completion result. It preserves current advanced capabilities, shared owners, stored-data readability, recovery behavior, and accepted compatibility contracts while still proving the slim ordinary-user navigation remains healthy.

The known Browser deep link to `/custom-study?mode=marked` remains a separate live caller/consumer contract mismatch. It was recorded and left unchanged. It is not evidence that Browser, Marker, Custom Study, Special Study, or any compatibility slice is deletable.

## Scope and safety

- actual deleted item count: `0`;
- production code/config/test changes: `0`;
- G-04 reclassification: none;
- destructive Backup / Restore / Portable Data / Knowledge Hygiene actions: none;
- pre-existing user and sibling dirty assets: excluded from this closure and preserved;
- G-06: not entered;
- next task: not entered.

G-05 is therefore accepted as a valid no-op milestone closure. G-06 and G-GATE remain `TODO`.
