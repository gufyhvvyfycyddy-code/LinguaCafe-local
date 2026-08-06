import assert from 'node:assert/strict';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import {
    classifyPath,
    collectWorkspaceInventory,
    parsePorcelainLine,
    parsePorcelainStatus,
    renderTextReport,
    summarizeEntries,
} from '../../scripts/workspace-inventory.mjs';

const REPO_ROOT = path.resolve(fileURLToPath(new URL('../../', import.meta.url)));
const SCRIPT = path.join(REPO_ROOT, 'scripts', 'workspace-inventory.mjs');

test('parsePorcelainLine parses XY-prefixed records', () => {
    assert.deepEqual(parsePorcelainLine(' M app/Foo.php'), {
        indexStatus: ' ',
        worktreeStatus: 'M',
        path: 'app/Foo.php',
    });
    assert.deepEqual(parsePorcelainLine('M  app/Foo.php'), {
        indexStatus: 'M',
        worktreeStatus: ' ',
        path: 'app/Foo.php',
    });
    assert.deepEqual(parsePorcelainLine('MM app/Foo.php'), {
        indexStatus: 'M',
        worktreeStatus: 'M',
        path: 'app/Foo.php',
    });
    assert.deepEqual(parsePorcelainLine('D  app/Gone.php'), {
        indexStatus: 'D',
        worktreeStatus: ' ',
        path: 'app/Gone.php',
    });
    assert.deepEqual(parsePorcelainLine('?? resources/js/new.js'), {
        indexStatus: '?',
        worktreeStatus: '?',
        path: 'resources/js/new.js',
    });
    assert.deepEqual(parsePorcelainLine('?? my file.txt'), {
        indexStatus: '?',
        worktreeStatus: '?',
        path: 'my file.txt',
    });
});

test('parsePorcelainStatus reads -z rename/copy as new path then old path', () => {
    const entries = parsePorcelainStatus('R  app/new.php\0app/old.php\0C  app/b.php\0app/a.php\0');
    assert.equal(entries.length, 2);
    assert.deepEqual(entries[0], {
        indexStatus: 'R',
        worktreeStatus: ' ',
        path: 'app/new.php',
        originalPath: 'app/old.php',
    });
    assert.deepEqual(entries[1], {
        indexStatus: 'C',
        worktreeStatus: ' ',
        path: 'app/b.php',
        originalPath: 'app/a.php',
    });
});

test('parsePorcelainStatus keeps ordinary entries and paths with spaces intact', () => {
    const entries = parsePorcelainStatus(' M app/Foo.php\0?? my file.txt\0 D docs/notes.md\0');
    assert.equal(entries.length, 3);
    assert.deepEqual(entries[0], { indexStatus: ' ', worktreeStatus: 'M', path: 'app/Foo.php' });
    assert.deepEqual(entries[1], { indexStatus: '?', worktreeStatus: '?', path: 'my file.txt' });
    assert.deepEqual(entries[2], { indexStatus: ' ', worktreeStatus: 'D', path: 'docs/notes.md' });
});

test('parsePorcelainStatus handles worktree-side rename and trailing NUL', () => {
    const entries = parsePorcelainStatus(' R app/y.txt\0app/x.txt\0');
    assert.equal(entries.length, 1);
    assert.deepEqual(entries[0], {
        indexStatus: ' ',
        worktreeStatus: 'R',
        path: 'app/y.txt',
        originalPath: 'app/x.txt',
    });
});

test('classifyPath buckets into all seven categories', () => {
    assert.equal(classifyPath('app/Services/Thing.php'), 'source');
    assert.equal(classifyPath('resources/js/components/Thing.vue'), 'source');
    assert.equal(classifyPath('routes/web.php'), 'source');
    assert.equal(classifyPath('mobile/android/app/build.gradle'), 'source');
    assert.equal(classifyPath('mobile/android/app/src/main/java/MainActivity.java'), 'source');
    assert.equal(classifyPath('mobile/ios/App/App/AppDelegate.swift'), 'source');
    assert.equal(classifyPath('mobile/ios/App/App/Info.plist'), 'source');
    assert.equal(classifyPath('mobile/ios/App/App/Base.lproj/Main.storyboard'), 'source');
    assert.equal(classifyPath('mobile/android/app/src/main/res/mipmap-hdpi/ic_launcher.png'), 'source');
    assert.equal(classifyPath('mobile/android/gradlew'), 'source');
    assert.equal(classifyPath('mobile/.gitignore'), 'source');
    assert.equal(classifyPath('scripts/windows/tokenizer-process.ps1'), 'source');
    assert.equal(classifyPath('scripts/windows/tokenizer-task-runner.ps1'), 'source');
    assert.equal(classifyPath('tests/js/WorkspaceInventory.test.mjs'), 'test');
    assert.equal(classifyPath('tests/Feature/ThingTest.php'), 'test');
    assert.equal(classifyPath('tests/js/helper.spec.mjs'), 'test');
    assert.equal(classifyPath('mobile/android/app/src/test/java/ExampleUnitTest.java'), 'test');
    assert.equal(classifyPath('mobile/android/app/src/androidTest/java/ExampleInstrumentedTest.java'), 'test');
    assert.equal(classifyPath('docs/DOCUMENTATION_INDEX.md'), 'documentation');
    assert.equal(classifyPath('README.md'), 'documentation');
    assert.equal(classifyPath('AGENTS.md'), 'documentation');
    assert.equal(classifyPath('docs/handoff.txt'), 'documentation');
    assert.equal(classifyPath('database/migrations/2024_01_01_create_x.php'), 'migration');
    assert.equal(classifyPath('public/js/app.js'), 'generated');
    assert.equal(classifyPath('node_modules/foo/index.js'), 'generated');
    assert.equal(classifyPath('storage/logs/laravel.log'), 'generated');
    assert.equal(classifyPath('vendor/autoload.php'), 'generated');
    assert.equal(classifyPath('tmp/scratch.log'), 'temporary');
    assert.equal(classifyPath('notes.tmp'), 'temporary');
    assert.equal(classifyPath('.DS_Store'), 'temporary');
    assert.equal(classifyPath('randomfile.bin'), 'unknown');
    assert.equal(classifyPath('assets/data.dat'), 'unknown');
});

test('summarizeEntries tallies statuses, categories and dangerous untracked', () => {
    const entries = [
        { indexStatus: ' ', worktreeStatus: 'M', path: 'app/Foo.php' },
        { indexStatus: 'M', worktreeStatus: ' ', path: 'app/Bar.php' },
        { indexStatus: 'D', worktreeStatus: ' ', path: 'app/Gone.php' },
        { indexStatus: '?', worktreeStatus: '?', path: 'resources/js/new.js' },
        { indexStatus: '?', worktreeStatus: '?', path: 'docs/notes.md' },
        { indexStatus: '?', worktreeStatus: '?', path: 'tmp/scratch.log' },
        { indexStatus: ' ', worktreeStatus: 'M', path: 'tests/js/x.test.mjs' },
    ];
    const summary = summarizeEntries(entries);

    assert.equal(summary.total, 7);
    assert.equal(summary.trackedModified, 3);
    assert.equal(summary.trackedDeleted, 1);
    assert.equal(summary.untracked, 3);
    assert.equal(summary.byCategory.source, 4);
    assert.equal(summary.byCategory.test, 1);
    assert.equal(summary.byCategory.documentation, 1);
    assert.equal(summary.byCategory.temporary, 1);
    assert.deepEqual(summary.dangerousUntracked, [
        { path: 'resources/js/new.js', category: 'source' },
        { path: 'docs/notes.md', category: 'documentation' },
    ]);
});

test('renderTextReport lists dangerous untracked items and omits harmless ones', () => {
    const entries = [
        { indexStatus: '?', worktreeStatus: '?', path: 'app/Leak.php' },
        { indexStatus: '?', worktreeStatus: '?', path: 'database/migrations/2025_m.php' },
        { indexStatus: '?', worktreeStatus: '?', path: 'tests/js/fresh.test.mjs' },
        { indexStatus: '?', worktreeStatus: '?', path: 'notes.tmp' },
    ];
    const inventory = {
        cwd: '/repo',
        head: { sha: 'abc123', branch: 'master', originMaster: 'abc123' },
        entries,
        summary: summarizeEntries(entries),
    };

    const output = renderTextReport(inventory);
    assert.match(output, /DANGEROUS untracked items/);
    assert.match(output, /\[source\] app\/Leak\.php/);
    assert.match(output, /\[migration\] database\/migrations\/2025_m\.php/);
    assert.match(output, /\[test\] tests\/js\/fresh\.test\.mjs/);
    assert.doesNotMatch(output, /notes\.tmp/);
    assert.match(output, /tracked modified: 0/);
    assert.match(output, /untracked:\s+4/);
    assert.match(output, /HEAD aligned with origin\/master/);
});

test('renderTextReport shows HEAD NOT aligned with origin/master', () => {
    const inventory = {
        cwd: '/repo',
        head: { sha: 'abc123', branch: 'master', originMaster: 'def456' },
        entries: [],
        summary: summarizeEntries([]),
    };

    const output = renderTextReport(inventory);
    assert.match(output, /HEAD NOT aligned with origin\/master/);
});

test('renderTextReport reports when there are no dangerous untracked items', () => {
    const inventory = {
        cwd: '/repo',
        head: { sha: 'abc123', branch: 'master', originMaster: null },
        entries: [
            { indexStatus: '?', worktreeStatus: '?', path: 'tmp/scratch.log' },
            { indexStatus: ' ', worktreeStatus: 'M', path: 'app/Foo.php' },
        ],
        summary: summarizeEntries([
            { indexStatus: '?', worktreeStatus: '?', path: 'tmp/scratch.log' },
            { indexStatus: ' ', worktreeStatus: 'M', path: 'app/Foo.php' },
        ]),
    };

    const output = renderTextReport(inventory);
    assert.match(output, /No dangerous untracked items/);
    assert.doesNotMatch(output, /DANGEROUS untracked items/);
});

test('collectWorkspaceInventory builds the JSON inventory shape from canned git results', () => {
    const statusOut = ' M app/Foo.php\0?? resources/js/new.js\0R  app/new.php\0app/old.php\0';
    const fakeGit = (args) => {
        const command = ['git', ...args];
        if (args[0] === 'status') {
            return { command, ok: true, stdout: statusOut, stderr: '' };
        }
        if (args[0] === 'rev-parse' && args[1] === 'HEAD') {
            return { command, ok: true, stdout: 'abc123', stderr: '' };
        }
        if (args[0] === 'rev-parse' && args.includes('--abbrev-ref')) {
            return { command, ok: true, stdout: 'master', stderr: '' };
        }
        if (args[0] === 'rev-parse' && args[1] === 'origin/master') {
            return { command, ok: true, stdout: 'def456', stderr: '' };
        }
        throw new Error('unexpected git call: ' + args.join(' '));
    };

    const inventory = collectWorkspaceInventory({ cwd: '/repo', git: fakeGit });

    assert.equal(inventory.ok, true);
    assert.equal(inventory.cwd, '/repo');
    assert.deepEqual(inventory.head, { sha: 'abc123', branch: 'master', originMaster: 'def456' });
    assert.deepEqual(inventory.errors, []);
    assert.equal(inventory.entries.length, 3);
    assert.deepEqual(inventory.entries[0], { indexStatus: ' ', worktreeStatus: 'M', path: 'app/Foo.php' });
    assert.deepEqual(inventory.entries[2], {
        indexStatus: 'R',
        worktreeStatus: ' ',
        path: 'app/new.php',
        originalPath: 'app/old.php',
    });
    assert.equal(inventory.summary.untracked, 1);
    assert.equal(inventory.summary.trackedModified, 2);
    assert.deepEqual(inventory.summary.dangerousUntracked, [
        { path: 'resources/js/new.js', category: 'source' },
    ]);

    const json = JSON.parse(JSON.stringify(inventory));
    for (const key of ['cwd', 'ok', 'head', 'entries', 'summary', 'errors']) {
        assert.ok(key in json, `inventory JSON has ${key}`);
    }
    assert.deepEqual(Object.keys(json.head).sort(), ['branch', 'originMaster', 'sha']);
    for (const key of ['total', 'trackedModified', 'trackedDeleted', 'untracked', 'byCategory', 'dangerousUntracked']) {
        assert.ok(key in json.summary, `summary JSON has ${key}`);
    }
});

test('collectWorkspaceInventory records read-only git failures', () => {
    const fakeGit = (args) => ({
        command: ['git', ...args],
        ok: false,
        stdout: '',
        stderr: 'fatal: not a git repository',
    });

    const inventory = collectWorkspaceInventory({ cwd: '/not-a-repo', git: fakeGit });

    assert.equal(inventory.ok, false);
    assert.equal(inventory.entries.length, 0);
    assert.equal(inventory.head.sha, null);
    assert.equal(inventory.head.branch, null);
    assert.equal(inventory.head.originMaster, null);
    assert.equal(inventory.errors.length, 4);
    assert.equal(
        inventory.errors[0].command,
        'git status --porcelain=v1 -z --untracked-files=all'
    );
});

test('module can be imported from node -e without running main', () => {
    const moduleUrl = pathToFileURL(SCRIPT).href;
    const code = `import(${JSON.stringify(moduleUrl)}).then((module) => { process.stdout.write(typeof module.collectWorkspaceInventory); });`;
    const result = spawnSync(process.execPath, ['--input-type=module', '-e', code], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
    });

    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout, 'function');
});

test('CLI --json emits the inventory structure and exits 0', () => {
    const result = spawnSync(process.execPath, [SCRIPT, '--json'], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
    });

    assert.equal(result.status, 0, result.stderr);
    const data = JSON.parse(result.stdout);
    assert.equal(typeof data.cwd, 'string');
    assert.equal(data.ok, true);
    assert.equal(typeof data.head.sha, 'string');
    assert.equal(typeof data.head.branch, 'string');
    assert.equal(typeof data.head.originMaster, 'string');
    assert.ok(Array.isArray(data.entries));
    assert.ok(Array.isArray(data.errors));
    for (const key of ['total', 'trackedModified', 'trackedDeleted', 'untracked', 'byCategory', 'dangerousUntracked']) {
        assert.ok(key in data.summary, `CLI summary has ${key}`);
    }
    for (const category of ['source', 'test', 'documentation', 'migration', 'generated', 'temporary', 'unknown']) {
        assert.ok(category in data.summary.byCategory, `CLI byCategory has ${category}`);
    }
});

test('CLI exits non-zero when a git command fails', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'workspace-inventory-'));
    try {
        const result = spawnSync(process.execPath, [SCRIPT, '--json'], {
            cwd: tmp,
            encoding: 'utf8',
        });

        assert.notEqual(result.status, 0);
        const data = JSON.parse(result.stdout);
        assert.equal(data.ok, false);
        assert.ok(data.errors.length >= 1);
        assert.equal(data.head.sha, null);
        assert.equal(data.entries.length, 0);
    } finally {
        fs.rmSync(tmp, { recursive: true, force: true });
    }
});
