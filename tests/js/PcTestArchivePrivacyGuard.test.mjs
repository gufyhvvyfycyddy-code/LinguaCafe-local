import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

const checkGitAttributes = paths => {
    const result = spawnSync('git', ['check-attr', 'export-ignore', '--', ...paths], {
        cwd: root,
        encoding: 'utf8',
    });
    assert.equal(result.status, 0, result.stderr || 'git check-attr failed');

    return new Map(
        result.stdout.trim().split(/\r?\n/).map(line => {
            const [path, attribute, value] = line.split(': ');
            assert.equal(attribute, 'export-ignore', `unexpected attribute output: ${line}`);
            return [path, value];
        }),
    );
};

test('PC packaging excludes privacy directories at every path depth', () => {
    const denied = [
        'output',
        'output/root.txt',
        'nested/output',
        'nested/output/deep/file.txt',
        'screenshots',
        'nested/screenshots/deep/image.png',
        'audit_screenshots',
        'nested/audit_screenshots/deep/report.png',
        '.env',
        '.envrc',
        '.env_backup',
        '.env-prod',
        'nested/.env',
        'nested/.envrc',
        'nested/.env_backup',
        'nested/.env-prod',
        'nested/credentials.json',
        'nested/cookies.txt',
        'nested/.secrets/config.json',
        'nested/.codex/session.json',
        'nested/.playwright-cli/state.json',
        'nested/private.pem',
        'nested/private.key',
        'nested/private.p12',
    ];
    const retained = [
        'tools/jmdict_conjugation/data',
        'tools/jmdict_conjugation/data/entry.json',
        'storage/framework/cache/data',
        'storage/framework/cache/data/cache.bin',
    ];
    const attributes = checkGitAttributes([...denied, ...retained]);

    for (const path of denied) {
        assert.equal(attributes.get(path), 'set', `${path} must be export-ignored`);
    }
    for (const path of retained) {
        assert.equal(attributes.get(path), 'unspecified', `${path} must remain archivable`);
    }

    const dockerIgnore = readFileSync(join(root, '.dockerignore'), 'utf8');
    for (const directory of ['output', 'screenshots', 'audit_screenshots']) {
        assert.match(dockerIgnore, new RegExp(`^${directory}$`, 'm'));
        const dockerRules = dockerIgnore.split(/\r?\n/);
        assert.ok(dockerRules.includes(`**/${directory}`));
        assert.ok(dockerRules.includes(`**/${directory}/**`));
    }
    for (const rule of ['.env*', '**/.env*', '**/cookies.txt', 'credentials*', '**/.codex', '**/.codex/**', '**/.playwright-cli', '**/.playwright-cli/**']) {
        assert.ok(dockerIgnore.split(/\r?\n/).includes(rule), `${rule} must be present`);
    }
    assert.doesNotMatch(
        dockerIgnore,
        /(?:^|\r?\n)\s*(?:\*\*\/)?data(?:\/\*\*)?\s*(?:\r?\n|$)/,
        'legitimate data directories must not be broadly ignored',
    );
});
