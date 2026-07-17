import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const plan = fs.readFileSync(
    path.join(root, 'docs/plans/anki-maximal-alignment-gap-audit-and-forward-plan.md'),
    'utf8',
);

assert.match(plan, /Draft planning input; not implementation authorization/);
assert.match(plan, /https:\/\/github\.com\/ankitects\/anki/);
assert.match(plan, /https:\/\/docs\.ankiweb\.net\/searching\.html/);
assert.match(plan, /https:\/\/docs\.ankiweb\.net\/filtered-decks\.html/);
assert.match(plan, /https:\/\/docs\.ankiweb\.net\/stats\.html/);
assert.match(plan, /https:\/\/docs\.ankiweb\.net\/backups\.html/);
assert.match(plan, /https:\/\/addon-docs\.ankiweb\.net\/background-ops\.html/);

const phases = [
    '### Phase 3 completion',
    '### Phase 4 — Marker + Custom Study 1B',
    '### Phase 5 — Reviewer convergence',
    '### Phase 6 — Reader UX and responsibility extraction',
    '### Phase 7 — Service convergence and disabled-provider preflight accepted',
    '### Phase 8 — Search and filtered-study parity',
    '### Phase 9 — Browser/reviewer lifecycle parity',
    '### Phase 10 — Analytics parity',
    '### Phase 11 — Portability and recovery',
    '### Phase 12 — Organization, presentation, and cross-device UX',
    '### Phase 13 — Extension boundary',
];
let previous = -1;
for (const phase of phases) {
    const current = plan.indexOf(phase);
    assert.ok(current > previous, `phase must exist in order: ${phase}`);
    previous = current;
}

assert.match(plan, /generic Anki Note\/NoteType\/CardTemplate domain/);
assert.match(plan, /do not add generic decks\/subdecks/);
assert.match(plan, /### Pass 4 — diminishing returns/);
assert.match(plan, /### Pass 5 — official-source recheck after Phases 4–7/);
assert.match(plan, /Pending lifecycle, preview\/final package and candidate normalization, confirmed-card generation, source binding, and disabled-provider browser acceptance are accepted under ADR-0032/);
assert.match(plan, /The user explicitly chose to keep the real provider disabled/);
assert.match(plan, /cost-estimate availability/);
assert.match(plan, /five stateless preview-only modes including marked cards/);
assert.match(plan, /shared request coordination and failure recovery/);
assert.match(plan, /active-only lookup sidebar/);
assert.doesNotMatch(plan, /Still missing or incomplete:\n\n1\. Card-level colored marker/);
assert.doesNotMatch(plan, /Still missing or incomplete:\n\n1\. Marked-card mode/);
assert.match(plan, /delete remains unapproved/);
assert.match(plan, /does not authorize Phase 3C-3, a migration, real deletion/);

console.log('Anki maximal-alignment forward plan guard passed.');
