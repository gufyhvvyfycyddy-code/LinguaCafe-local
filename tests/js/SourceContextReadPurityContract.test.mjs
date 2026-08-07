import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (relativePath) => readFileSync(new URL(`../../${relativePath}`, import.meta.url), 'utf8');

const controller = read('app/Http/Controllers/SenseSourceContextController.php');
const service = read('app/Services/SenseSourceContextService.php');
const manage = read('resources/js/components/ReviewCards/ReviewCardManage.vue');
const legacyReview = read('resources/js/components/Review/Review.vue');
const sourceDialog = read('resources/js/components/Review/SenseSourceDialog.vue');
const customStudy = read('resources/js/components/CustomStudy/CustomStudySession.vue');
const specialStudy = read('resources/js/components/CustomStudy/SpecialStudySession.vue');
const allSourceClients = [manage, legacyReview, sourceDialog, customStudy, specialStudy].join('\n');

assert.doesNotMatch(
    controller,
    /request\(\)->boolean\(['"]read_only['"]\)/,
    'a GET query parameter must not opt source-context reads into persistence',
);
assert.match(
    controller,
    /sourceContext\([\s\S]*?selected_language,[\s\S]*?\$id,[\s\S]*?false,[\s\S]*?\)/,
    'single-source controller must explicitly select read-only recovery',
);
assert.match(
    controller,
    /sourceContextList\([\s\S]*?preferred_occurrence_id[\s\S]*?false,[\s\S]*?\)/,
    'multi-source controller must explicitly select read-only recovery',
);
assert.match(
    service,
    /sourceContext\([^)]*bool\s+\$allowRecoveryWriteBack\s*=\s*false\)/s,
    'single-source service default must be read-only',
);
assert.match(
    service,
    /sourceContextList\([\s\S]*?bool\s+\$allowRecoveryWriteBack\s*=\s*false[\s\S]*?\)/,
    'multi-source service default must be read-only',
);

assert.doesNotMatch(manage, /wrote back chapter_id/i);
assert.doesNotMatch(
    manage,
    /source_kind\s*===\s*['"]chapter_recovered['"][\s\S]{0,500}?loadData\(/,
    'viewing recovered source must not assume an implicit database repair',
);

for (const [name, source] of [
    ['ReviewCardManage', manage],
    ['Review.vue', legacyReview],
    ['SenseSourceDialog', sourceDialog],
]) {
    assert.match(source, /sourceContextRequestSequence/, `${name} must identify the latest source-context request`);
    assert.match(source, /requestSequence\s*!==\s*this\.sourceContextRequestSequence/, `${name} must ignore stale source-context responses`);
}

assert.match(customStudy, /read_only:\s*1/);
assert.match(specialStudy, /read_only:\s*1/);
assert.doesNotMatch(
    allSourceClients,
    /axios\.(?:post|put|patch|delete)\([^\n]*source-context/,
    'source viewing clients must use no mutation method',
);

console.log('Source-context read-purity contract passed.');
