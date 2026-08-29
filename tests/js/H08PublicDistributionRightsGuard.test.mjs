import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

test('H-08 public distribution rights guard passes the current release tree', () => {
    const output = execFileSync(
        process.execPath,
        ['scripts/public-release-rights-guard.mjs'],
        { cwd: root, encoding: 'utf8' },
    );

    assert.match(output, /H-08 public release rights guard passed/);
});
