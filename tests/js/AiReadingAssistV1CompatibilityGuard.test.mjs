// PAB R2 V1 compatibility surface guard.
// Existing Feature suites remain the behavioral authority; this guard prevents
// V2 work from silently deleting V1 endpoints/schema/translation consumers.
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const routes = readFileSync(join(root, 'routes/web.php'), 'utf8');
const service = readFileSync(join(root, 'app/Services/AiReadingAssistService.php'), 'utf8');

for (const route of [
    '/chapters/ai-assist/source',
    '/chapters/ai-assist/preview',
    '/chapters/ai-assist/confirm',
    '/chapters/ai-assist/current/{chapterId}',
    '/chapters/ai-assist/lookup/{chapterId}',
]) {
    assert.ok(routes.includes(route), `V1 compatibility route must remain: ${route}`);
}

assert.match(service, /linguacafe_ai_reading_assist_v1/, 'V1 schema dispatch must remain explicit');
assert.match(service, /sentence_translations/, 'V1 sentence translation shape must remain available');
assert.match(service, /vocabulary_items/, 'V1 vocabulary suggestion shape must remain available');
assert.match(service, /phrase_items/, 'V1 phrase suggestion shape must remain available');

for (const featureTest of [
    'tests/Feature/AiReadingAssistPreviewTest.php',
    'tests/Feature/AiReadingAssistConfirmTest.php',
    'tests/Feature/AiReadingAssistCurrentTest.php',
    'tests/Feature/AiReadingAssistLookupTest.php',
    'tests/Feature/AiReadingAssistSentenceAlignmentTest.php',
]) {
    assert.ok(existsSync(join(root, featureTest)), `Lane 4 V1 regression suite must remain available: ${featureTest}`);
}

console.log('AiReadingAssistV1CompatibilityGuard passed.');
