# Windows PC Test — HANDOFF-06 — Real PC Main-Flow Smoke

Date: 2026-09-02
Status: DONE / I-04 ACCEPT
Product build under test: `1fe0fae9d6308118bcf20af7674853d74112aa6a`
Current Goal documentation baseline at closeout: `7bd4097ffb3b7295e7fb52380d9b153edbe56fd7` or later

## What was completed

The installed Windows PC test runtime was exercised through real browser DOM against its dedicated loopback service at `http://127.0.0.1:9391` and dedicated database `linguacafe_pc_test`.

Real user-flow evidence:

1. canonical `/login` accepted the dedicated PC-test administrator and opened Home;
2. Home loaded with the expected four main areas plus the administrator entry;
3. Library started empty;
4. Import -> Paste Text created `PC Test Reading Smoke` with chapter `Smoke Chapter`;
5. processing completed with 50 total words / 46 unique words;
6. Reader rendered the imported text;
7. clicking `curiosity` opened the real lookup drawer;
8. ECDICT returned `n. 好奇心, 新奇的事物, 珍品` and the real source sentence;
9. ECDICT prefill -> Save New Sense created one noun WordSense and one formal Sense Review card;
10. WordSense library showed exactly that saved `curiosity / noun` sense;
11. Sense Review showed one due card with the real source example sentence;
12. Show Answer -> `记得 / Good` completed one formal rating;
13. User Settings opened the dedicated PC-test account normally;
14. Admin -> Users showed exactly one user and `管理员=是`;
15. returning Home showed `今日复习 1`, `今天阅读新学 1 / 10 个词义`, Good=1, confirmed sense=1 and source binding=1.

## Browser / API evidence

- Real MCP Chrome operated the PC-test runtime on port 9391.
- Console warnings/errors during the accepted flow: none.
- All 66 observed XHR/fetch requests returned HTTP 200, including import, Reader session/evidence, ECDICT search, manual sense creation, WordSense list, Sense Review interval/lifecycle/leech reads, formal rating, Admin users and Home statistics.
- The MCP Chrome clipboard bridge could not safely transfer the host-side random PC-test password: it pasted 334 characters while the actual local password length is 27. This was classified as an acceptance-tool limitation, not a LinguaCafe authentication defect. A one-shot loopback-only secret bridge on `127.0.0.1` was used once, returned only to the PC-test origin, then exited; no password was written to Git, reports or chat.

## Database reconciliation

Read-only queries against `linguacafe_pc_test` after the flow returned:

- users: 1, administrators: 1
- books: 1
- chapters: 1
- word_senses: 1
- review_cards: 1
- review_logs: 1
- word_sense_occurrences: 1

Formal review facts:

- ReviewCard target type: `sense`
- FSRS state after Good: `learning`
- reps: 1
- lapses: 0
- ReviewLog rating: `good`
- state transition: `new -> learning`
- ReviewLog source: `sense_review`
- log is not undone

Source evidence facts:

- WordSenseOccurrence points to WordSense 1 and chapter 1
- lemma: `curiosity`
- POS: `NOUN`
- status: `bound`
- source: `reading_occurrence`

## Product conclusion

I-04 is ACCEPT. The first Windows PC test build has a real end-to-end path through Home -> Library/import -> Reader/dictionary -> WordSense -> formal Sense Review -> User Settings -> Admin, with UI/API/database evidence agreeing.

This does not close I-GATE. The product owner still needs ordinary hands-on use of the desktop shortcut and should report UX/product defects into `docs/testing/windows-pc-test-feedback-log.md`. I-05 therefore remains ACTIVE and I-GATE remains TODO.

## Next step

Leave the installed desktop shortcut available for product-owner testing. Record every new owner-reported issue with the exact build, observed behavior and expected behavior before changing code. Do not start a public Windows installer/signing/update project unless the product owner asks for it.
