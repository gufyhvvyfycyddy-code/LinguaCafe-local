import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ownerPath = join(root, 'app/Services/AiStudyCardPendingLifecycleService.php');
const facadePath = join(root, 'app/Services/AiStudyCardPendingItemService.php');

assert.ok(existsSync(ownerPath), 'pending lifecycle owner must exist');
const owner = readFileSync(ownerPath, 'utf8');
const facade = readFileSync(facadePath, 'utf8');

for (const method of ['dismiss', 'restore', 'markProcessed', 'emptyLifecycleInfo']) {
    assert.match(owner, new RegExp(`public function ${method}\\(`), `${method} must belong to lifecycle owner`);
}
assert.match(facade, /private AiStudyCardPendingLifecycleService \$pendingLifecycleService/);
assert.doesNotMatch(facade, /private function markPendingItemProcessed/);
assert.doesNotMatch(facade, /private function emptyPendingLifecycleInfo/);
assert.doesNotMatch(facade, /->update\(\['status' => AiStudyCardPendingItem::STATUS_DISMISSED/);

console.log('AiStudyCardPendingLifecycleArchitectureGuard: lifecycle ownership passed.');
