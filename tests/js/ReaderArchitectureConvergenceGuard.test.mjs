import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const service = readFileSync(join(root, 'app/Services/TextBlockService.php'), 'utf8');

assert.match(
    service,
    /\$this->readerDataService->loadFsrsFamiliarityLookup\(\)/,
    'TextBlockService must delegate FSRS familiarity ownership to ReaderDataService',
);
assert.doesNotMatch(
    service,
    /private function loadFsrsFamiliarityLookup/,
    'TextBlockService must not retain a duplicate FSRS familiarity implementation',
);
assert.doesNotMatch(
    service,
    /if \(\$this->readerDataService\)/,
    'the constructor-owned ReaderDataService must not have an unreachable legacy fallback branch',
);

console.log('ReaderArchitectureConvergenceGuard: reader facade delegation contract passed.');
