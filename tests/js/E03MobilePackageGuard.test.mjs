import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = relative => readFileSync(new URL(`../../${relative}`, import.meta.url), 'utf8');
const articlePackage = read('app/Services/MobileArticlePackageService.php');
const reviewPackage = read('app/Services/MobileReviewPackageService.php');
const api = read('mobile/src/api.ts');
const offline = read('mobile/src/offlineRepository.ts');
const ui = read('mobile/src/ui.ts');

for (const field of [
  'tokens',
  'sentence_translations',
  'sense_summaries',
  'dictionary_version',
  'dictionary_summaries',
]) {
  assert.match(articlePackage, new RegExp(`'${field}'`));
}
assert.match(articlePackage, /'dictionary_version' => \$dictionaryVersion/);
assert.match(reviewPackage, /confirmedSenseCardQuery/);
assert.match(reviewPackage, /package_type' => 'short_term_review'/);

assert.match(api, /chapterPackage\(/);
assert.match(api, /dictionary\(term: string\)/);
assert.match(api, /\/dictionary\/lookup\?term=/);
assert.match(api, /horizon_days=7&limit=50/);
assert.doesNotMatch(api, /horizon_days=0&limit=50/);
assert.match(offline, /chapter_packages/);
assert.match(ui, /readerPackage\?\.dictionary_summaries\[term\]/);
assert.match(ui, /if \(this\.usingOfflineSnapshot\)/);
assert.match(ui, /this\.api\.dictionary\(term\)/);
assert.match(ui, /if \(this\.noteNetworkFailure\(error\)\)/);
assert.match(ui, /离线词典摘要/);

console.log('E-03 mobile package guard passed.');
