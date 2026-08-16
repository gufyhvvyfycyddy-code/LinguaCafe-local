import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const ui = readFileSync(new URL('../../mobile/src/ui.ts', import.meta.url), 'utf8');
const touch = readFileSync(new URL('../../mobile/src/readerTouchSelection.ts', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../../mobile/src/styles.css', import.meta.url), 'utf8');

assert.match(ui, /addEventListener\('pointerdown'/);
assert.match(ui, /addEventListener\('pointermove'/);
assert.match(ui, /readerPhrase\(this\.readerTokens/);
assert.match(ui, /history\.pushState\(\{ linguacafeScreen: this\.screen, linguacafeLookup: true \}/);
assert.match(ui, /addEventListener\('popstate'/);
assert.match(ui, /history\.pushState\(\{ linguacafeScreen: screen \}/);
assert.match(touch, /READER_LONG_PRESS_MS = 450/);
assert.match(touch, /READER_SCROLL_CANCEL_PX = 10/);
assert.match(touch, /pos: 'phrase'/);
assert.match(styles, /env\(safe-area-inset-bottom\)/);
assert.match(styles, /\.lookup-sheet \{ max-height: 88dvh/);
assert.match(styles, /\.rating-grid \{ display: grid; grid-template-columns: repeat\(2, 1fr\)/);
assert.doesNotMatch(ui, /@capacitor\/app|backButton/);

console.log('E-06 mobile interaction guard passed.');
