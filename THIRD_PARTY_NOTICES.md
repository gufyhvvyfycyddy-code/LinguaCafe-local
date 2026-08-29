# LinguaCafe third-party notices

This file records third-party assets that are intentionally shipped with LinguaCafe public packages. Standard license texts for directly bundled assets are kept in `LICENSES/`; dependency packages and generated bundles also retain their own embedded notices where available.

Last verified: 2026-08-29.

## Project license

LinguaCafe is distributed under GNU GPL version 3. The canonical project license text is `LICENSE`.

The `LICENSES/` directory follows the SPDX/REUSE-style convention for complete third-party license texts. H-08 verified these texts against SPDX License List 3.28.0, obtained through the `spdx-license-list` 6.12.0 data package. This directory records license terms; copyright and asset-specific attribution remain in this notice or the dependency's own metadata.

## Bundled fonts

The following tracked font binaries contain their own license metadata identifying the SIL Open Font License 1.1 (OFL 1.1):

- `public/default/fonts/DefaultNotoSansJP.otf` — Noto Sans JP 2.002; copyright Adobe; Noto trademark Google.
- `public/default/fonts/DefaultNotoSansSC.ttf` — Noto Sans SC 2.004-H2; copyright Adobe.
- `public/default/fonts/DefaultOpenSans.ttf` — Open Sans 3.003; copyright The Open Sans Project Authors.
- `public/fonts/VarelaRound-Regular.ttf` — Varela Round 3.010; copyright The Varela Round Project Authors.

License text: `LICENSES/OFL-1.1.txt`.

## Font Awesome Free

`public/webfonts/fa-*-400.*` and `public/webfonts/fa-solid-900.*` are Font Awesome Free 5.15.4 assets inherited from upstream LinguaCafe. The tracked Font Awesome SVG files identify the Free license split: fonts under SIL OFL 1.1, icon artwork under CC BY 4.0, and code under MIT. Brand marks remain trademarks of their respective owners and are used only for their identifying purpose.

Relevant license texts: `LICENSES/OFL-1.1.txt`, `LICENSES/CC-BY-4.0.txt`, and `LICENSES/MIT.txt`.

## Material Design Icons

LinguaCafe uses `@mdi/font` 7.4.47. The installed package's `LICENSE` states that its web and desktop fonts are Apache-2.0, non-font/non-icon code is MIT, and individual icons may retain their respective source licenses.

Project: https://pictogrammers.com/library/mdi/
License text: `LICENSES/Apache-2.0.txt`; MIT-licensed non-font code is covered by `LICENSES/MIT.txt`.

## JavaScript production bundle notices

`public/js/app.js.LICENSE.txt` preserves notices emitted for third-party JavaScript included in the historical production bundle. Keep that file with any release that includes the corresponding bundle.

## Python tokenizer image

The H-08 inventory checked the packages actually installed in the production tokenizer image rather than inferring licenses from package names. Direct runtime dependencies include:

- spaCy 3.8.x — MIT.
- `en_core_web_sm` 3.8.0 — MIT.
- LemmInflect 0.2.3 — MIT; the package metadata field is incomplete, while the project license is MIT.
- pykakasi 2.3.x — GPL-3.0-or-later.
- `pinyin` 0.4.0 by Lx Yu — its package metadata says `BSD`, while the installed `pinyin-0.4.0.dist-info/LICENSE` contains the MIT permission notice and copyright `Copyright (c) 2016, Lx Yu`; LinguaCafe preserves the actual installed license notice as the evidence source.
- PySubParser 1.7.x — MIT.
- newspaper3k 0.2.8 — MIT; upstream identifies Lucas Ou-Yang and `Copyright 2014, Lucas Ou-Yang`. The installed wheel does not contain a separate LICENSE file, so this notice plus `LICENSES/MIT.txt` supplies the redistribution notice in LinguaCafe's public package.
- EbookLib 0.20 — GNU Affero General Public License.
- youtube-transcript-api 1.2.x — MIT.
- Bottle 0.13.x — MIT.
- lxml 6.1.x — BSD-3-Clause.

The production image was inspected directly. spaCy, the English model, LemmInflect, pykakasi, pinyin, PySubParser, EbookLib, youtube-transcript-api, Bottle, lxml, and lxml_html_clean retain license/copyright files in their installed distribution metadata. newspaper3k 0.2.8 is the observed exception and is covered explicitly above. The Dockerfile installs these packages from their normal package/project distribution sources rather than copying undocumented local binaries.

## JMdict / JMdictDB compatibility assets

`tools/jmdict_conjugation/conj.py` contains the original Stuart McGraw copyright header and grants GNU GPL version 2 or any later version. `tools/jmdict_conjugation/data/README.txt` records that the conjugation CSV files are exact copies from JMdictDB revision `hg-20180525-61238f`. These files remain only because current compatibility contracts still preserve lower JMDict/Kanji owners.

JMdict/JMdictDB project information: https://www.edrdg.org/wiki/index.php/JMdict-EDICT_Dictionary_Project and https://gitlab.com/yamagoya/jmdictdb
License text for the retained GPL v2-or-later compatibility code: `LICENSES/GPL-2.0-or-later.txt`.

The user-visible historical dictionary/resource acknowledgements remain in `resources/js/components/Home/Attributions.vue`.

## Upstream-inherited flag PNGs are excluded

The 42 files under `public/images/flags/` are byte-for-byte inherited from the upstream LinguaCafe repository, but neither the current repository nor upstream documents a dependable source/license for that raster set. H-08 therefore does not claim redistribution rights for those files.

Public product source no longer references the PNGs. `.dockerignore` excludes the directory from Docker build contexts, and `.gitattributes` marks the files `export-ignore` so the supported `git archive` source-release path excludes them. The private development history may still contain the inherited files for compatibility/history, but they are not approved public release assets.

## User and exam material

LinguaCafe does not grant redistribution rights over material a user imports. A user's private right to upload/read a document, exam paper, subtitle, book, audio file, or video does not make that content part of the LinguaCafe public package.

`scripts/public-release-rights-guard.mjs` fails the release gate when common document/media formats or user storage files become Git-tracked without an explicit later review. Any deliberate future inclusion must first receive a provenance/license decision and then update that guard and this inventory together.
