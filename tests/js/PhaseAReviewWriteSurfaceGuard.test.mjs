// Phase A review write-surface guard (static).
// 冻结语义：Phase A（AI Reading Assist V2 preview/confirm + evidence）绝不写 ReviewLog/ReviewCard/FSRS；
// 不触达 legacy bulkConfirmHighConfidence / numeric confidence threshold / auto_fsrs_allowed（AC §J, SH:514-529）。
// V2 生产文件由 Lane1 落地；文件不存在时 guard 保持通过并输出等待说明（Lane4 重跑后持续生效）。
import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const appServices = join(root, 'app', 'Services');

function listFiles(dir, out = []) {
    if (!existsSync(dir)) return out;
    for (const entry of readdirSync(dir)) {
        const full = join(dir, entry);
        if (statSync(full).isDirectory()) listFiles(full, out);
        else if (entry.endsWith('.php')) out.push(full);
    }
    return out;
}

const forbidden = [
    /ReviewLog\s*::\s*(create|forceCreate|insert)/,
    /recordReview\s*\(/,
    /recordReviewWithLog\s*\(/,
    /FsrsSchedulingService/,
    /->schedule\s*\(/,
    /bulkConfirmHighConfidence\s*\(/,
    /auto_fsrs_allowed/,
    /0\.9\d*\s*[<=>]/,
];

// 1) 现有 V1 AI assist service 不得触碰正式评分面（历史不变量，AC §3.5）
const v1ServicePath = join(appServices, 'AiReadingAssistService.php');
assert.ok(existsSync(v1ServicePath), 'AiReadingAssistService.php must exist');
const v1Source = readFileSync(v1ServicePath, 'utf8');
for (const pattern of forbidden) {
    assert.doesNotMatch(v1Source, pattern, `AiReadingAssistService.php must not ${pattern}`);
}

// 2) 未来 Phase A owner 文件（Lane1 落地后生效）
const phaseACandidates = listFiles(appServices).filter((p) =>
    /AiReadingAssistV2ContractService\.php$/.test(p) ||
    /ReadingOccurrenceSenseEvidenceService\.php$/.test(p)
);
if (phaseACandidates.length === 0) {
    console.log('PhaseAReviewWriteSurfaceGuard: V2/evidence services not yet present (Lane1 pending); guard idle.');
} else {
    for (const file of phaseACandidates) {
        const source = readFileSync(file, 'utf8');
        for (const pattern of forbidden) {
            assert.doesNotMatch(source, pattern, `${file} must not ${pattern}`);
        }
        // 三层存储边界（AC §1.7）：evidence owner 绝不 import FSRS writer
        if (file.endsWith('ReadingOccurrenceSenseEvidenceService.php')) {
            assert.doesNotMatch(source, /ReviewCardService/);
            assert.doesNotMatch(source, /FsrsSchedulingService/);
        }
    }
    console.log(`PhaseAReviewWriteSurfaceGuard: checked ${phaseACandidates.length} Phase A service file(s).`);
}

// 3) Reader 侧 AI assist UI 不得直接调用评分端点
const readerAiAssistPath = join(root, 'resources/js/components/TextReader/TextReaderAiAssist.vue');
assert.ok(existsSync(readerAiAssistPath), 'TextReaderAiAssist.vue must exist');
const readerSource = readFileSync(readerAiAssistPath, 'utf8');
assert.doesNotMatch(readerSource, /\/reviews\/senses\//, 'reader AI assist UI must not call sense rating endpoint');
assert.doesNotMatch(readerSource, /\/review-cards\//, 'reader AI assist UI must not call review card mutation endpoints');

console.log('PhaseAReviewWriteSurfaceGuard passed.');
