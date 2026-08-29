# H-08 Public Package Rights Acceptance — 2026-08-29

## Verdict

**Accepted / DONE.**

The accepted H-08 product commit is:

`c65b49ad73f57afac8e0e38a4a6767f0a76ba38a` — `feat: close H-08 public distribution rights gate`

H-08 closes the public package/content-rights gate defined by H-07. The supported release boundary is the built Docker image plus the supported `git archive` source-package path. H-08 does not claim that making the full private Git repository/history public is automatically safe; a future repository-publication decision must review Git-tracked historical assets separately.

## Mature distribution pattern adopted

H-08 used the current SPDX/REUSE convention instead of inventing a project-specific licensing format:

- the project keeps its canonical GNU GPL version 3 text in root `LICENSE`;
- directly bundled third-party license texts are kept under `LICENSES/` with SPDX identifiers;
- asset-specific copyright/provenance information is recorded in `THIRD_PARTY_NOTICES.md`;
- dependency packages and generated bundles retain their own embedded notices where available;
- unresolved-provenance decorative assets fail closed by leaving the supported public package rather than receiving a guessed license.

External reference points checked on 2026-08-29:

- REUSE Specification 3.3: https://reuse.software/spec/
- SPDX Online Tools / License List 3.28.0: https://tools.spdx.org/app/about/
- GNU GPLv3 section 13 / AGPL compatibility: https://www.gnu.org/licenses/gpl.en.html
- GNU GPL FAQ: https://www.gnu.org/licenses/gpl-faq.en.html

## Public package inventory result

Fresh Git inventory found no tracked CET-4, CET-6, postgraduate entrance-exam paper PDF/EPUB, subtitle, audio, video, or complete exam-content bundle in the repository. Phase F real samples therefore are not being redistributed through the supported H-08 package path.

A permanent release guard now rejects newly tracked review-required content formats including common document, ebook, subtitle, audio, video, and Office formats. It also rejects tracked user/import storage under `public/storage/` and non-placeholder content under `storage/app/`.

The guard is exposed as:

`npm run release:rights`

and implemented by:

`scripts/public-release-rights-guard.mjs`

## Unresolved upstream flag PNGs

The 42 files under `public/images/flags/` were verified as inherited byte-for-byte from upstream LinguaCafe, but no dependable source/license statement exists in the current repository or upstream attribution material.

H-08 therefore does not assert redistribution rights for these raster assets.

The supported public package now applies two independent boundaries:

1. `.dockerignore` excludes `public/images/flags` from Docker build context.
2. `.gitattributes` marks `/public/images/flags/** export-ignore` for the supported `git archive` source release.

All current product/manual runtime references to `/images/flags/` were removed. Language identity remains visible as text or an existing Material Design translation icon where a marker is useful. No language selection, dictionary configuration, subtitle-language, admin-language, or user-manual function depends on the removed PNGs.

After product commit, a real `git archive HEAD` inspection showed:

- `public/images/flags/` empty directory entry: present;
- PNG files under that directory: **0**;
- non-directory entries under that directory: **0**;
- `THIRD_PARTY_NOTICES.md`: present;
- all five required `LICENSES/*.txt` files: present;
- `scripts/public-release-rights-guard.mjs`: present.

The empty directory entry contains no protected artwork and is not a distribution-rights issue.

## Bundled license texts

H-08 added these complete standard texts:

- `LICENSES/OFL-1.1.txt`
- `LICENSES/Apache-2.0.txt`
- `LICENSES/CC-BY-4.0.txt`
- `LICENSES/GPL-2.0-or-later.txt`
- `LICENSES/MIT.txt`

The texts were obtained from SPDX License List 3.28.0 data and compared against the stored project copies after normalizing line endings. Result: **5/5 exact content matches**.

The repository notice records the directly bundled font/icon/JMdict compatibility assets that consume these terms.

## Font and icon evidence

The actual bundled font binaries were inspected rather than inferring license from a current website listing:

- Noto Sans JP 2.002 — SIL OFL 1.1;
- Noto Sans SC 2.004-H2 — SIL OFL 1.1;
- Open Sans 3.003 — SIL OFL 1.1;
- Varela Round 3.010 — SIL OFL 1.1.

Font Awesome Free 5.15.4 tracked asset headers identify the expected Free split: font files under OFL 1.1, icon artwork under CC BY 4.0, and code under MIT.

`@mdi/font` 7.4.47 installed package metadata/license identifies Apache-2.0 for the font/icon assets and MIT for non-font/non-icon code as described in `THIRD_PARTY_NOTICES.md`.

## JMdict compatibility evidence

The lower JMDict/Kanji compatibility owner remains required by the current product compatibility contract. It was not deleted merely to reduce license inventory.

`tools/jmdict_conjugation/conj.py` carries the Stuart McGraw GPL version 2 or later header. `tools/jmdict_conjugation/data/README.txt` records the copied JMdictDB revision provenance. GPL-2.0-or-later can select GPLv3 for compatibility with the LinguaCafe GPLv3 project.

## Python tokenizer image

The production tokenizer image was inspected using installed package metadata and actual `dist-info`/license files.

Direct runtime packages include spaCy, the English model, LemmInflect, pykakasi, pinyin, PySubParser, newspaper3k, EbookLib, youtube-transcript-api, Bottle, lxml, and lxml_html_clean. Packages that ship license files retain those files in the image. `newspaper3k` reports MIT metadata but its wheel does not contain a separate license file, so the root notice records its copyright attribution and the repository carries the standard MIT text.

EbookLib remains an actual tokenizer import and is therefore not deleted as dead dependency. Its installed distribution carries GNU Affero GPL license material. GNU GPLv3 section 13 explicitly permits GPLv3 and AGPLv3 covered work to be combined; H-08 therefore does not treat the AGPL package as an automatic incompatibility blocker. The tokenizer image still retains the package's own license material.

## Project metadata

H-08 removed Laravel skeleton publication metadata:

- Composer package name: `linguacafe/linguacafe`;
- Composer description: LinguaCafe-specific;
- Composer license: `GPL-3.0-only`;
- npm package name: `linguacafe`;
- npm license: `GPL-3.0-only`.

Refreshing Composer lock metadata used Composer's lock-only update path. The resulting `composer.lock` diff changes only `content-hash`; no dependency package/version changed.

Final PHP 8.4 Composer validation:

`./composer.json is valid`

## Automated verification

Final focused H-08 gate:

- `npm run release:rights`: PASS; **2060 tracked paths checked** after commit;
- H-08 + language-selection JS contracts: **6/6 PASS**;
- `git diff --check`: PASS before product commit;
- PHP 8.4 `composer validate --no-check-publish`: PASS;
- final Docker build from the current worktree: PASS.

Full JS suite was also executed during H-08:

- **474 total**;
- **469 passed**;
- **5 failed**.

The five failures are existing documentation/rule guards outside the H-08 change surface:

- `LongTermRulesDocsGuard.test.mjs`;
- `M13ReviewSettingsUiGuard.test.mjs`;
- `M9IosMvpGuard.test.mjs`;
- `MasterPlanIntegrityContract.test.mjs`;
- `TargetModeCompositeTaskRuleGuard.test.mjs`.

H-08 did not weaken or skip those tests to manufacture a green result. Their target documents/features are not changed by the H-08 product commit.

## Final production image evidence

Final image:

`linguacafe-h08-web-rights-final:latest`

Fresh runtime inspection reported:

- PHP: **8.4.25**;
- `public/images/flags` directory: absent;
- flag PNG files: **0**;
- runtime `/images/flags/` source references: **0**;
- `THIRD_PARTY_NOTICES.md`: present;
- `LICENSES` files: **5**.

The image build also compiled the production frontend successfully with Laravel Mix. Existing Sass deprecation warnings remain warnings and did not block compilation.

## Real browser acceptance

H-08 used a real testing-only browser session rather than substituting source inspection for UI behavior.

The isolated testing database started empty. The normal `/setup` page created the task-provided local testing administrator. The browser then verified the current language/admin/manual surfaces after flag removal:

- admin Languages page rendered language information without flag-image requests;
- admin Dictionaries page opened through real UI navigation;
- User Manual language material rendered text while flag-image count remained zero;
- Network evidence contained no `/images/flags/*` request;
- exact 430px viewport rendered the account/mobile navigation surface without horizontal overflow.

The concrete test password is intentionally absent from repository documentation.

## Testing cleanup and production-tool incidents

After browser acceptance:

- the testing browser pages were closed;
- the PAB PHP server was stopped;
- port 8875 had no listener;
- TestingDatabaseLease ended `active=false` and `stale_metadata=false`;
- the task-created testing administrator was removed by a testing-only precise cleanup under the official lease;
- the isolated testing database returned to zero users.

One FastCtx forced termination left stale lease metadata/sentinel temporarily. The existing PAB recovery cycle correctly detected and removed the stale sentinel, created its own validation sentinel, and cleaned that sentinel on exit. Because recovery worked as designed and the final lease/port state was clean, no testing-harness product change was justified.

The REUSE helper also exposed a local tooling issue: the machine's default `py -3` pointed to Python 3.14 alpha and crashed while importing the REUSE CLI. H-08 isolated this compliance helper to the already installed stable Python 3.12. This did not change LinguaCafe runtime dependencies. Direct REUSE license download remained affected by local outbound-network policy, so SPDX License List 3.28.0 text was retrieved through the machine's working npm mirror and independently compared with stored files.

## Independent review

A final read-only OpenCode `opencode/hy3-free` review inspected the exact H-08 diff and found:

- Critical: none;
- Required: none.

Its AGPL compatibility concern was independently checked against GNU GPLv3 section 13 and the GNU GPL FAQ; GPLv3 and AGPLv3 explicitly permit combination, so that concern is not a blocker.

Its note that the unresolved flag PNGs remain Git-tracked is relevant only if the entire Git repository/history is later made public. H-08's authoritative boundary is the supported public package. Docker and `git archive` releases exclude the PNG files. A future repository-publication milestone must separately decide whether to remove/rewrite historical assets before exposing the repository itself.

## Cleanup / compliance confirmation

H-08 did not read or modify `.env`, did not modify `AGENTS.md`, did not run destructive database reset/wipe commands, did not use force push, and did not perform a production deployment or store submission.

## H-09 handoff boundary

H-08 is closed. H-09 owns Android release preparation.

H-09 must use the existing Android/M7 assets and current product boundary. It must verify the current Android build/package/privacy/device-smoke path against current Android/Google requirements and real local tooling. It must not submit an application to Google Play automatically.
