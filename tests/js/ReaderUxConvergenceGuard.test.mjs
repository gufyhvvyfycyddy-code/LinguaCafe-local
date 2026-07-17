import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const reader = readFileSync(join(root, 'resources/js/components/TextReader/TextReader.vue'), 'utf8');
const textBlock = readFileSync(join(root, 'resources/js/components/Text/TextBlockGroup.vue'), 'utf8');
const settings = readFileSync(join(root, 'resources/js/components/TextReader/TextReaderSettings.vue'), 'utf8');

assert.match(reader, /getReaderContentRightPadding/, 'TextReader must use the shared visible-sidebar padding contract');
assert.match(reader, /'padding-right': readerContentRightPadding/, 'reader padding must follow actual sidebar visibility');
assert.match(reader, /readerContentRightPadding\(\)/, 'reader must expose reactive content padding');

assert.match(
    textBlock,
    /v-if="\$props\.vocabularySidebar && \$props\.vocabularySidebarFits && \$store\.state\.vocabularyBox\.active && !\$store\.state\.vocabularyBox\.sidebarHidden"/,
    'sidebar rendering must require an active vocabulary selection',
);
assert.match(
    textBlock,
    /this\.\$props\.vocabularySidebarFits && this\.\$props\.vocabularySidebar && this\.\$store\.state\.vocabularyBox\.active/,
    'position updates must not unhide an inactive sidebar',
);
assert.match(
    textBlock,
    /setPositionLeft', vocabBoxArea\.right - sidebarW/,
    'the fixed sidebar must occupy the reserved right-hand track instead of starting beyond it',
);

assert.match(settings, /悬停自动查词：/, 'hover dictionary search needs a task-oriented label');
assert.ok(
    (settings.match(/<v-row v-if="settings\.vocabularyHoverBox">/g) || []).length >= 3,
    'hover search, delay, and position controls must hide when hover vocabulary is disabled',
);

console.log('ReaderUxConvergenceGuard: attention, sidebar visibility, and dependent-setting contracts passed.');
