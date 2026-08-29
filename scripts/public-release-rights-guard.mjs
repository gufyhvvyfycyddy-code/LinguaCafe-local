import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const errors = [];

const fail = message => errors.push(message);
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const tracked = execFileSync('git', ['ls-files', '-z'], { cwd: root })
    .toString('utf8')
    .split('\0')
    .filter(Boolean);

const licenseText = read('LICENSE');
if (!licenseText.includes('GNU GENERAL PUBLIC LICENSE') || !licenseText.includes('Version 3, 29 June 2007')) {
    fail('Root LICENSE must remain GNU GPL version 3.');
}

const composer = JSON.parse(read('composer.json'));
if (composer.name !== 'linguacafe/linguacafe') {
    fail(`composer.json name must identify LinguaCafe, found ${composer.name ?? 'missing'}.`);
}
if (composer.license !== 'GPL-3.0-only') {
    fail(`composer.json license must be GPL-3.0-only, found ${composer.license ?? 'missing'}.`);
}

const packageJson = JSON.parse(read('package.json'));
if (packageJson.name !== 'linguacafe') {
    fail(`package.json name must identify LinguaCafe, found ${packageJson.name ?? 'missing'}.`);
}
if (packageJson.license !== 'GPL-3.0-only') {
    fail(`package.json license must be GPL-3.0-only, found ${packageJson.license ?? 'missing'}.`);
}

if (!fs.existsSync(path.join(root, 'THIRD_PARTY_NOTICES.md'))) {
    fail('THIRD_PARTY_NOTICES.md is required for public releases.');
}

const requiredLicenseTexts = new Map([
    ['LICENSES/OFL-1.1.txt', 'Version 1.1 - 26 February 2007'],
    ['LICENSES/Apache-2.0.txt', 'Version 2.0, January 2004'],
    ['LICENSES/CC-BY-4.0.txt', 'Creative Commons Attribution 4.0 International Public License'],
    ['LICENSES/GPL-2.0-or-later.txt', 'Version 2, June 1991'],
    ['LICENSES/MIT.txt', 'Permission is hereby granted, free of charge'],
]);
for (const [relativePath, marker] of requiredLicenseTexts) {
    const absolutePath = path.join(root, relativePath);
    if (!fs.existsSync(absolutePath)) {
        fail(`Required third-party license text is missing: ${relativePath}`);
        continue;
    }
    if (!fs.readFileSync(absolutePath, 'utf8').includes(marker)) {
        fail(`Third-party license text is not the expected standard text: ${relativePath}`);
    }
}

const dockerIgnore = read('.dockerignore').split(/\r?\n/).map(line => line.trim());
if (!dockerIgnore.includes('public/images/flags')) {
    fail('.dockerignore must exclude public/images/flags because their provenance is unresolved.');
}

const flagAttribute = execFileSync(
    'git',
    ['check-attr', 'export-ignore', '--', 'public/images/flags/english.png'],
    { cwd: root },
).toString('utf8');
if (!flagAttribute.includes('export-ignore: set')) {
    fail('git archive must exclude public/images/flags via export-ignore.');
}

const forbiddenExtensions = new Set([
    '.pdf', '.epub', '.mobi', '.azw3',
    '.mp3', '.m4a', '.wav', '.flac',
    '.mp4', '.mkv', '.avi', '.mov',
    '.srt', '.ass', '.vtt',
    '.docx', '.pptx', '.xlsx',
]);

for (const file of tracked) {
    const normalized = file.replaceAll('\\', '/');
    const basename = path.posix.basename(normalized);
    const extension = path.posix.extname(normalized).toLowerCase();

    if (forbiddenExtensions.has(extension)) {
        fail(`Public source tree contains review-required content file: ${normalized}`);
    }

    if (normalized.startsWith('public/storage/')) {
        fail(`Public storage content must not be tracked: ${normalized}`);
    }

    if (normalized.startsWith('storage/app/') && !['.gitignore', '.gitkeep'].includes(basename)) {
        fail(`User/imported storage content must not be tracked: ${normalized}`);
    }
}

const sourceExtensions = new Set(['.vue', '.js', '.mjs', '.php', '.md', '.scss', '.css', '.html']);
const runtimeSourcePrefixes = ['app/', 'routes/', 'resources/', 'manual/', 'mobile/'];
for (const file of tracked) {
    if (
        !runtimeSourcePrefixes.some(prefix => file.startsWith(prefix))
        || !sourceExtensions.has(path.posix.extname(file).toLowerCase())
    ) {
        continue;
    }

    const absolutePath = path.join(root, file);
    if (!fs.existsSync(absolutePath)) {
        continue;
    }

    const content = fs.readFileSync(absolutePath, 'utf8');
    if (content.includes('/images/flags/') || content.includes('images/flags/')) {
        fail(`Tracked source still depends on unresolved flag artwork: ${file}`);
    }
}

if (errors.length) {
    console.error('H-08 public release rights guard failed:');
    for (const error of errors) {
        console.error(`- ${error}`);
    }
    process.exit(1);
}

console.log(`H-08 public release rights guard passed (${tracked.length} tracked paths checked).`);
