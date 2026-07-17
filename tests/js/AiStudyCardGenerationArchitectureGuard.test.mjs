import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ownerPath = join(root, 'app/Services/AiStudyCardGenerationService.php');
const facadePath = join(root, 'app/Services/AiStudyCardPendingItemService.php');

assert.ok(existsSync(ownerPath), 'confirmed-card generation owner must exist');
const owner = readFileSync(ownerPath, 'utf8');
const facade = readFileSync(facadePath, 'utf8');

assert.match(owner, /public function generateCardsFromConfirmedCandidates\(/);
assert.match(owner, /private function resolveSourceBindingStatus\(/);
assert.match(facade, /private AiStudyCardGenerationService \$generationService/);
assert.match(facade, /generationService->generateCardsFromConfirmedCandidates\(/);
assert.doesNotMatch(facade, /WordSenseOccurrence::/);
assert.doesNotMatch(facade, /ReviewCard::/);
assert.doesNotMatch(facade, /DB::transaction/);

console.log('AiStudyCardGenerationArchitectureGuard: generation and source binding ownership passed.');
