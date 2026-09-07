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

console.log('Optional integration fail-soft contract passed.');
