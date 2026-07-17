import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ownerPath = join(root, 'app/Services/AiStudyCardPendingPackageService.php');
const facadePath = join(root, 'app/Services/AiStudyCardPendingItemService.php');

assert.ok(existsSync(ownerPath), 'pending package owner must exist');
const owner = readFileSync(ownerPath, 'utf8');
const facade = readFileSync(facadePath, 'utf8');

for (const method of ['buildPreviewPackage', 'buildFinalCandidatesPackage']) {
    assert.match(owner, new RegExp(`public function ${method}\\(`), `${method} must belong to package owner`);
    assert.match(facade, new RegExp(`packageService->${method}\\(`), `${method} facade must delegate`);
}
assert.match(facade, /private AiStudyCardPendingPackageService \$packageService/);
assert.doesNotMatch(facade, /private function dedupeKey/);
assert.doesNotMatch(facade, /private function normalizeAiRecommendation/);

console.log('AiStudyCardPendingPackageArchitectureGuard: package ownership passed.');
