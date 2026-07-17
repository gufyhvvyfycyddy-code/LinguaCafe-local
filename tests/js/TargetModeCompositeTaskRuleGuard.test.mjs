import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(.:)/, '$1')), '../..');
const rules = fs.readFileSync(path.join(root, 'docs/architecture/ai-development-rule-system.md'), 'utf8');

assert.match(rules, /Local Architecture Preflight/, 'feature and structural work must run a local architecture preflight');
assert.match(rules, /Which business\/module owner receives the behavior/, 'preflight must identify the owning module');
assert.match(rules, /Does the task add or change an observable interface/, 'preflight must identify interface changes');
assert.match(rules, /Which existing behaviors and consumers can regress/, 'preflight must identify regression scope');
assert.match(rules, /Feature delivery and broad refactoring are separate/, 'feature delivery and broad refactoring must remain separate');
assert.match(rules, /necessary small structural adjustment.*purpose, scope, and tests are explicit/s, 'small necessary structural changes must be disclosed and tested');
assert.match(rules, /Do not create an empty wrapper, duplicate DTO, generic service, repository, interface, or global state/, 'architecture work must not invent empty abstractions');
assert.match(rules, /Docs\/rules-only work must run rule guards, local-link checks, and `git diff --check`/, 'explicit docs/rules tasks must have their own executable closure');
assert.match(rules, /The local execution agent stops after the current task/, 'the local execution agent must stop after the current task');
assert.match(rules, /does not enter the next phase/, 'implementation must not auto-enter the next phase');

console.log('Current task-boundary and architecture-preflight rule contract passed.');
