import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = join(__dirname, '..', '..');
const servicePath = join(root, 'resources', 'js', 'services', 'ReaderWorkspaceSizingService.js');
const textBlockPath = join(root, 'resources', 'js', 'components', 'Text', 'TextBlockGroup.vue');

assert.ok(existsSync(servicePath), 'ReaderWorkspaceSizingService.js must exist');
assert.ok(existsSync(textBlockPath), 'TextBlockGroup.vue must exist');

const sizing = await import(pathToFileURL(servicePath).href);
const {
    getReaderSidebarWidthForWorkspace,
    getReaderSidebarCssWidthForWorkspace,
    getReaderSidebarReservationWidthForWorkspace,
    doesReaderSidebarFitWorkspace,
    isReaderSidebarVisible,
    getReaderContentRightPadding,
} = sizing;

assert.equal(typeof getReaderSidebarReservationWidthForWorkspace, 'function', 'reservation-width helper must exist');
assert.equal(typeof isReaderSidebarVisible, 'function', 'sidebar visibility helper must exist');
assert.equal(typeof getReaderContentRightPadding, 'function', 'content-padding helper must exist');

const widthCases = [
    [1920, 540],
    [1500, 540],
    [1499, 500],
    [1280, 500],
    [1279, 460],
    [1080, 460],
    [1079, 400],
    [900, 400],
];

for (const [workspaceWidth, expectedPanelWidth] of widthCases) {
    assert.equal(
        getReaderSidebarWidthForWorkspace(workspaceWidth),
        expectedPanelWidth,
        `workspace ${workspaceWidth}px should use ${expectedPanelWidth}px panel`,
    );
    assert.equal(
        getReaderSidebarCssWidthForWorkspace(workspaceWidth),
        `${expectedPanelWidth}px`,
        `workspace ${workspaceWidth}px should expose matching CSS width`,
    );
    assert.equal(
        getReaderSidebarReservationWidthForWorkspace(workspaceWidth),
        expectedPanelWidth + 24,
        `workspace ${workspaceWidth}px should reserve a visible 24px outer gutter`,
    );
}

assert.equal(
    getReaderSidebarReservationWidthForWorkspace(1524) - getReaderSidebarWidthForWorkspace(1524),
    24,
    'the reported 1524px workspace must retain a wider visible boundary outside the sidebar',
);

assert.equal(doesReaderSidebarFitWorkspace(1500), true, 'wide workspace should still fit the narrowed sidebar');
assert.equal(doesReaderSidebarFitWorkspace(900), false, 'narrow workspace should keep the existing non-sidebar fallback');

assert.equal(
    isReaderSidebarVisible({ enabled: true, fits: true, active: true, hidden: false }),
    true,
    'an active, enabled, fitting sidebar should be visible',
);
assert.equal(
    isReaderSidebarVisible({ enabled: true, fits: true, active: false, hidden: false }),
    false,
    'an inactive vocabulary box must not leave an empty sidebar visible',
);
assert.equal(
    isReaderSidebarVisible({ enabled: true, fits: true, active: true, hidden: true }),
    false,
    'an explicitly hidden sidebar must stay hidden',
);
assert.equal(
    getReaderContentRightPadding({
        enabled: true,
        fits: true,
        active: true,
        hidden: false,
        workspaceWidth: 1524,
    }),
    '564px',
    'visible sidebar should reserve the shared panel and gutter width',
);
assert.equal(
    getReaderContentRightPadding({
        enabled: true,
        fits: true,
        active: false,
        hidden: false,
        workspaceWidth: 1524,
    }),
    '0px',
    'inactive vocabulary UI must not permanently narrow the reader',
);

const textBlockSource = readFileSync(textBlockPath, 'utf8');
assert.match(
    textBlockSource,
    /getReaderSidebarWidthForWorkspace/,
    'TextBlockGroup must consume the shared width contract',
);
assert.doesNotMatch(
    textBlockSource,
    /if \(width >= 1500\) return 600/,
    'TextBlockGroup must not retain the old duplicated 600px breakpoint',
);

console.log('ReaderWorkspaceSizingService: panel, gutter, and shared-consumer contracts passed.');
