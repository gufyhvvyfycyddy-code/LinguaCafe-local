import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

const CATEGORIES = ['source', 'test', 'documentation', 'migration', 'generated', 'temporary', 'unknown'];
const DANGEROUS_CATEGORIES = ['source', 'test', 'documentation', 'migration'];
const STATUS_CHARS = new Set([' ', 'M', 'A', 'D', 'R', 'C', 'U', '?', '!']);

export function parsePorcelainLine(line) {
    if (line.length >= 3 && STATUS_CHARS.has(line[0]) && STATUS_CHARS.has(line[1]) && line[2] === ' ') {
        return { indexStatus: line[0], worktreeStatus: line[1], path: line.slice(3) };
    }
    return { indexStatus: '', worktreeStatus: '', path: line };
}

export function parsePorcelainStatus(rawStatus) {
    const parts = (rawStatus ?? '').split('\0').filter((part) => part.length > 0);
    const entries = [];
    for (let i = 0; i < parts.length; i++) {
        const entry = parsePorcelainLine(parts[i]);
        const isRenameOrCopy =
            entry.indexStatus === 'R' ||
            entry.indexStatus === 'C' ||
            entry.worktreeStatus === 'R' ||
            entry.worktreeStatus === 'C';
        if (isRenameOrCopy && i + 1 < parts.length) {
            entry.originalPath = parts[i + 1];
            i += 1;
        }
        entries.push(entry);
    }
    return entries;
}

export function classifyPath(path) {
    const lower = path.replaceAll('\\', '/').toLowerCase();

    if (lower.includes('database/migrations')) {
        return 'migration';
    }
    if (
        lower.startsWith('tests/') ||
        lower.includes('/tests/') ||
        lower.includes('/src/test/') ||
        lower.includes('/src/androidtest/') ||
        lower.includes('.test.') ||
        lower.includes('.spec.') ||
        lower.endsWith('_test.py')
    ) {
        return 'test';
    }
    if (
        lower.startsWith('node_modules/') ||
        lower.startsWith('vendor/') ||
        lower.startsWith('public/') ||
        lower.startsWith('storage/') ||
        lower.startsWith('dist/') ||
        lower.startsWith('build/') ||
        lower.startsWith('coverage/') ||
        lower.includes('bootstrap/cache/')
    ) {
        return 'generated';
    }
    if (
        lower.endsWith('.log') ||
        lower.endsWith('.tmp') ||
        lower.endsWith('.temp') ||
        lower.endsWith('.swp') ||
        lower.endsWith('.bak') ||
        lower.endsWith('.orig') ||
        lower.endsWith('.rej') ||
        lower.endsWith('.ds_store') ||
        lower.endsWith('~') ||
        lower.endsWith('.cache')
    ) {
        return 'temporary';
    }
    if (
        lower.endsWith('.md') ||
        lower.endsWith('.markdown') ||
        lower.endsWith('.rst') ||
        lower.endsWith('.txt') ||
        lower.endsWith('.adoc') ||
        lower.startsWith('docs/') ||
        lower.includes('/docs/') ||
        lower.startsWith('readme') ||
        lower.includes('changelog') ||
        lower.includes('license') ||
        lower.startsWith('agents.md')
    ) {
        return 'documentation';
    }
    if (
        lower.endsWith('.php') ||
        lower.endsWith('.vue') ||
        lower.endsWith('.js') ||
        lower.endsWith('.mjs') ||
        lower.endsWith('.cjs') ||
        lower.endsWith('.ts') ||
        lower.endsWith('.jsx') ||
        lower.endsWith('.tsx') ||
        lower.endsWith('.py') ||
        lower.endsWith('.css') ||
        lower.endsWith('.scss') ||
        lower.endsWith('.sass') ||
        lower.endsWith('.json') ||
        lower.endsWith('.xml') ||
        lower.endsWith('.yml') ||
        lower.endsWith('.yaml') ||
        lower.endsWith('.java') ||
        lower.endsWith('.kt') ||
        lower.endsWith('.kts') ||
        lower.endsWith('.swift') ||
        lower.endsWith('.gradle') ||
        lower.endsWith('.properties') ||
        lower.endsWith('.pro') ||
        lower.endsWith('.plist') ||
        lower.endsWith('.storyboard') ||
        lower.endsWith('.pbxproj') ||
        lower.endsWith('.xcconfig') ||
        lower.endsWith('.xcprivacy') ||
        lower.endsWith('.jar') ||
        lower.endsWith('.png') ||
        lower.endsWith('.jpg') ||
        lower.endsWith('.jpeg') ||
        lower.endsWith('.webp') ||
        lower.endsWith('.svg') ||
        lower.endsWith('.html') ||
        lower.endsWith('.sh') ||
        lower.endsWith('.ps1') ||
        lower.endsWith('.bat') ||
        lower.endsWith('.gitignore') ||
        lower.endsWith('.env.example') ||
        lower.endsWith('/gradlew')
    ) {
        return 'source';
    }
    return 'unknown';
}

export function summarizeEntries(entries) {
    const summary = {
        total: entries.length,
        trackedModified: 0,
        trackedDeleted: 0,
        untracked: 0,
        byCategory: Object.fromEntries(CATEGORIES.map((category) => [category, 0])),
        dangerousUntracked: [],
    };

    for (const entry of entries) {
        const { indexStatus, worktreeStatus, path } = entry;
        const category = classifyPath(path);
        summary.byCategory[category] += 1;

        if (indexStatus === '?' && worktreeStatus === '?') {
            summary.untracked += 1;
            if (DANGEROUS_CATEGORIES.includes(category)) {
                summary.dangerousUntracked.push({ path, category });
            }
        } else if (indexStatus === 'D' || worktreeStatus === 'D') {
            summary.trackedDeleted += 1;
        } else if (
            indexStatus === 'M' ||
            worktreeStatus === 'M' ||
            indexStatus === 'A' ||
            indexStatus === 'R' ||
            indexStatus === 'C'
        ) {
            summary.trackedModified += 1;
        }
    }
    return summary;
}

function runGit(args, cwd) {
    const result = spawnSync('git', args, { cwd, encoding: 'utf8' });
    return {
        command: ['git', ...args],
        ok: result.status === 0,
        stdout: (result.stdout ?? '').replace(/\r?\n$/, ''),
        stderr: (result.stderr ?? '').trim(),
    };
}

export function collectWorkspaceInventory(options = {}) {
    const cwd = options.cwd ?? process.cwd();
    const git = options.git ?? runGit;

    const status = git(['status', '--porcelain=v1', '-z', '--untracked-files=all'], cwd);
    const head = git(['rev-parse', 'HEAD'], cwd);
    const branch = git(['rev-parse', '--abbrev-ref', 'HEAD'], cwd);
    const originMaster = git(['rev-parse', 'origin/master'], cwd);

    const entries = status.ok ? parsePorcelainStatus(status.stdout) : [];

    const errors = [status, head, branch, originMaster]
        .filter((result) => !result.ok)
        .map((result) => ({ command: result.command.join(' '), stderr: result.stderr }));

    return {
        cwd,
        ok: errors.length === 0,
        head: {
            sha: head.ok ? head.stdout : null,
            branch: branch.ok ? branch.stdout : null,
            originMaster: originMaster.ok ? originMaster.stdout : null,
        },
        entries,
        summary: summarizeEntries(entries),
        errors,
    };
}

export function renderTextReport(inventory) {
    const summary = inventory.summary ?? summarizeEntries(inventory.entries ?? []);
    const lines = [];
    lines.push(`Workspace inventory for ${inventory.cwd ?? process.cwd()}`);
    lines.push('');
    lines.push(`Head: ${inventory.head?.sha ?? '(unknown)'} on ${inventory.head?.branch ?? '(unknown)'}`);
    if (inventory.head?.originMaster) {
        lines.push(`origin/master: ${inventory.head.originMaster}`);
        if (inventory.head?.sha) {
            lines.push(
                `HEAD ${inventory.head.sha === inventory.head.originMaster ? 'aligned' : 'NOT aligned'} with origin/master`
            );
        }
    }
    lines.push('');
    lines.push('Changes:');
    lines.push(`  tracked modified: ${summary.trackedModified}`);
    lines.push(`  tracked deleted:  ${summary.trackedDeleted}`);
    lines.push(`  untracked:        ${summary.untracked}`);
    lines.push('');
    lines.push('By category:');
    for (const category of CATEGORIES) {
        lines.push(`  ${category.padEnd(13)} ${summary.byCategory[category]}`);
    }
    lines.push('');
    if (summary.dangerousUntracked.length === 0) {
        lines.push('No dangerous untracked items.');
    } else {
        lines.push('DANGEROUS untracked items (source/test/migration/documentation):');
        for (const { path, category } of summary.dangerousUntracked) {
            lines.push(`  [${category}] ${path}`);
        }
    }
    return lines.join('\n');
}

export function main() {
    const asJson = process.argv.slice(2).includes('--json');
    const inventory = collectWorkspaceInventory();
    const output = asJson ? JSON.stringify(inventory, null, 2) : renderTextReport(inventory);
    process.stdout.write(output + '\n');
    // ponytail: exit non-zero only when a read-only git command failed.
    process.exitCode = inventory.ok ? 0 : 1;
}

const executedPath = process.argv[1];
if (executedPath && import.meta.url === pathToFileURL(executedPath).href) {
    main();
}
