// Phase B formal-rating write surface guard (static).
// 冻结语义（PAB-R2 Dispatch §7、SH:377-392、AGENTS §6）：
//  - reading_passive / reading_explicit 的正式评分只能进入 ReviewCardService formal writer；
//  - reading 评分路径不得直接 ReviewLog::create，不得直接调用 FsrsSchedulingService；
//  - 既有非评分型 ReviewLog 写入（例如 portable restore）不属于本 guard 的范围。
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const appRoot = join(root, 'app');
const reviewCardServicePath = join(root, 'app', 'Services', 'ReviewCardService.php');

function listFiles(dir, out = []) {
    if (!existsSync(dir)) return out;
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) listFiles(full, out);
        else if (entry.endsWith('.php')) out.push(full);
    }
    return out;
}

function executablePhpSource(file) {
    return readFileSync(file, 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '')
        .replace(/^\s*#.*$/gm, '');
}

assert.ok(existsSync(reviewCardServicePath), 'ReviewCardService.php must exist');
const formalWriter = executablePhpSource(reviewCardServicePath);
assert.match(formalWriter, /function\s+recordReview\s*\(/, 'formal writer must expose recordReview');
assert.match(formalWriter, /function\s+recordReviewWithLog\s*\(/, 'formal writer must expose recordReviewWithLog');
assert.match(formalWriter, /FsrsSchedulingService/, 'formal writer must own FsrsSchedulingService dependency');
assert.match(formalWriter, /->schedule\s*\(/, 'formal writer must call the FSRS scheduler');
assert.match(formalWriter, /ReviewLog\s*::\s*create\s*\(/, 'formal writer must create the ReviewLog after scheduling');

const allAppFiles = listFiles(appRoot);
const readingSourceFiles = allAppFiles.filter((file) =>
    /reading_passive|reading_explicit|SOURCE_READING_PASSIVE|SOURCE_READING_EXPLICIT/.test(executablePhpSource(file))
);

for (const file of readingSourceFiles) {
    const source = executablePhpSource(file);
    const rel = relative(root, file).replaceAll(String.fromCharCode(92), '/');

    // Source constants/contracts may mention the values without writing a rating.
    const participatesInRatingWrite = /recordReview(?:WithLog)?\s*\(|ReviewLog\s*::\s*(?:create|forceCreate)\s*\(|FsrsSchedulingService/.test(source);
    if (!participatesInRatingWrite) continue;

    if (!rel.endsWith('app/Services/ReviewCardService.php')) {
        assert.doesNotMatch(source, /ReviewLog\s*::\s*(?:create|forceCreate)\s*\(/, `${rel} must not create reading ReviewLog directly`);
        assert.doesNotMatch(source, /FsrsSchedulingService/, `${rel} must not call the scheduler directly`);
        assert.match(
            source,
            /(?:->|::)recordReview(?:WithLog)?\s*\(/,
            `${rel} must terminate reading ratings in ReviewCardService::recordReview / recordReviewWithLog`
        );
    }
}

assert.ok(
    readingSourceFiles.length > 0,
    'PhaseBFormalRatingWriteSurfaceGuard must resolve the concrete reading_passive/reading_explicit production surfaces',
);
console.log(`PhaseBFormalRatingWriteSurfaceGuard: checked ${readingSourceFiles.length} file(s) carrying reading rating source semantics.`);
console.log('PhaseBFormalRatingWriteSurfaceGuard passed.');
