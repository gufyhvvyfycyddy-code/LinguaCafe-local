import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts) => fs.readFileSync(path.join(root, ...parts), 'utf8');

const attributes = read('.gitattributes');
const dockerfile = read('docker', 'PhpDockerfile');
const patchBytes = fs.readFileSync(path.join(root, 'docker', 'fsrs-rs-php-php84.patch'));
const lockfile = read('docker', 'fsrs-rs-php-php84.Cargo.lock');

assert.match(attributes, /\*\.patch text eol=lf/);
assert.equal(patchBytes.includes(Buffer.from('\r\n')), false, 'FSRS patch must remain LF in Windows checkouts');

assert.match(dockerfile, /COPY docker\/fsrs-rs-php-php84\.Cargo\.lock \/tmp\/fsrs-rs-php-php84\.Cargo\.lock/);
assert.match(dockerfile, /cp \/tmp\/fsrs-rs-php-php84\.Cargo\.lock Cargo\.lock/);
assert.match(dockerfile, /cargo build --release --locked/);
assert.doesNotMatch(dockerfile, /cargo update -p ext-php-rs/);
assert.doesNotMatch(dockerfile, /Cargo\.lock' \| sha256sum -c/);

assert.match(lockfile, /name = "ext-php-rs"\nversion = "0\.15\.15"/);
assert.match(lockfile, /name = "fsrs-rs-php"/);

console.log('FSRS Docker reproducibility guard passed.');
