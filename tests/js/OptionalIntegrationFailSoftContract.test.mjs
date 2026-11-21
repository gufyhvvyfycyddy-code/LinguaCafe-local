import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

const settingValue = read('app/Services/Settings/SettingValueService.php');
const jellyfinService = read('app/Services/JellyfinService.php');
const jellyfinController = read('app/Http/Controllers/JellyfinController.php');
const ankiService = read('app/Services/AnkiApiService.php');
const ankiController = read('app/Http/Controllers/AnkiController.php');
const reader = read('resources/js/components/Text/TextBlockGroup.vue');

assert.match(
    settingValue,
    /function isJellyfinEnabled\(\)[\s\S]*?if \(!\$setting\) \{\s*return false;/,
    'missing Jellyfin enablement must default to disabled',
);
assert.match(
    settingValue,
    /'ankiAutoAddCards'\s*=>\s*\(bool\).*?\?\? false/s,
    'missing Anki auto-add setting must default off',
);
assert.match(
    settingValue,
    /'ankiShowNotifications'\s*=>\s*\(bool\).*?\?\? false/s,
    'missing Anki display setting must default off',
);

assert.match(
    jellyfinService,
    /getJellyfinCurrentlyPlayedSubtitles \(\)[\s\S]*?if \(!\$this->isConfigured\(\)\) \{\s*return \[\];/,
    'unconfigured Jellyfin must return an empty session list',
);
assert.match(jellyfinController, /'code'\s*=>\s*'JELLYFIN_UNAVAILABLE'/);
assert.match(jellyfinController, /\],\s*503\)/);
assert.doesNotMatch(jellyfinController, /abort\(500,\s*\$e->getMessage\(\)\)/);

assert.match(ankiService, /if \(trim\(\$this->ankiHost\) === ''\)/);
assert.match(ankiController, /'code'\s*=>\s*'ANKI_UNAVAILABLE'/);
assert.match(ankiController, /\],\s*503\)/);
assert.doesNotMatch(ankiController, /abort\(500,\s*\$e->getMessage\(\)\)/);

const ankiCall = reader.slice(reader.indexOf("axios.post('/anki/add-card'"), reader.indexOf('            removeSnackbar(', reader.indexOf("axios.post('/anki/add-card'")));
assert.notEqual(ankiCall.length, 0, 'Anki request block must be readable');
assert.ok(
    ankiCall.indexOf('.then((response) =>') < ankiCall.indexOf('.catch((error) =>'),
    'Anki success handler must run before catch so failures do not fall through into response.status',
);
assert.match(ankiCall, /error\?\.response\?\.data\?\.error\?\.message/);

// Execute the real import-selector mounted handler with simulated optional requests.
const importSelection = read('resources/js/components/Library/Import/ImportTypeSelection.vue');
const script = importSelection.match(/<script>([\s\S]*?)<\/script>/)?.[1]
    .replace(/^\s*import .*;\s*$/m, '')
    .replace('export default', 'return');
assert.ok(script, 'import selector script must be readable');

async function importFlags(jellyfinFails) {
    const axios = {
        get: url => url.includes('is-jellyfin-enabled')
            ? (jellyfinFails ? Promise.reject(new Error('synthetic optional failure')) : Promise.resolve({ data: false }))
            : Promise.resolve({ data: ['english'] }),
    };
    const component = new Function('axios', 'requestErrorMessage', script)(axios, () => 'feature unavailable');
    const state = { ...component.data(), $props: { language: 'english' } };
    component.mounted.call(state);
    await new Promise(resolve => setImmediate(resolve));
    return state;
}
const healthy = await importFlags(false);
const failedOptional = await importFlags(true);
for (const state of [healthy, failedOptional]) {
    assert.equal(state.loading, false);
    assert.equal(state.websiteImportSupported, true, 'unrelated website import must remain available');
    assert.equal(state.jellyfinEnabled, false);
    assert.equal(state.error, '', 'optional Jellyfin failure must not show an error for basic imports');
}

console.log('Optional integration fail-soft contract passed.');
