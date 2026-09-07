import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const dockerfile = fs.readFileSync(path.join(root, 'docker/PythonDockerfile'), 'utf8');
const requirements = fs.readFileSync(
    path.join(root, 'docker/python/requirements.lock.txt'),
    'utf8',
);

test('python tokenizer image installs one locked runtime instead of live spaCy downloads', () => {
    assert.match(dockerfile, /COPY docker\/python\/requirements\.lock\.txt/);
    assert.match(dockerfile, /pip install --user --no-cache-dir -r \/tmp\/requirements\.lock\.txt/);
    assert.doesNotMatch(dockerfile, /spacy download/);

    assert.match(requirements, /^spacy==3\.8\.16$/m);
    assert.match(requirements, /^lemminflect==0\.2\.3$/m);
    assert.match(
        dockerfile,
        /en_core_web_sm-3\.8\.0\/en_core_web_sm-3\.8\.0-py3-none-any\.whl/,
    );
    assert.match(
        dockerfile,
        /1932429db727d4bff3deed6b34cfc05df17794f4a52eeb26cf8928f7c1a0fb85[\s\S]*sha256sum -c/,
    );
});
