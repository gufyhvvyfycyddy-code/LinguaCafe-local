import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const mobileRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const distRoot = join(mobileRoot, 'dist');
const iosPublicRoot = join(mobileRoot, 'ios', 'App', 'App', 'public');

const requiredSafeguards = [
  '正式移动端仅允许 HTTPS',
  '服务器分页信息无效',
  '仅用于本地调试',
];

function fail(message) {
  throw new Error(`[ios-generated-web-integrity] ${message}`);
}

function sha256(path) {
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

function listFiles(root, current = root) {
  if (!existsSync(current)) {
    fail(`missing path: ${relative(mobileRoot, current)}`);
  }

  const files = [];
  for (const entry of readdirSync(current, { withFileTypes: true })) {
    const path = join(current, entry.name);
    if (entry.isDirectory()) {
      files.push(...listFiles(root, path));
    } else if (entry.isFile()) {
      files.push(relative(root, path).replaceAll('\\', '/'));
    }
  }
  return files.sort();
}

function requireExactFile(pathA, pathB, label) {
  const hashA = sha256(pathA);
  const hashB = sha256(pathB);
  if (hashA !== hashB) {
    fail(`${label} hash mismatch`);
  }
  return hashA;
}

function assetReference(indexHtml, extension) {
  const match = indexHtml.match(new RegExp(`(?:src|href)=["']([^"']+\\.${extension})["']`));
  if (!match) {
    fail(`index is missing a referenced .${extension} asset`);
  }
  return match[1].replace(/^\.\//, '');
}

for (const root of [distRoot, iosPublicRoot]) {
  if (!existsSync(join(root, 'index.html'))) {
    fail(`missing generated index: ${relative(mobileRoot, join(root, 'index.html'))}`);
  }
}

const distIndex = readFileSync(join(distRoot, 'index.html'), 'utf8');
const iosIndex = readFileSync(join(iosPublicRoot, 'index.html'), 'utf8');
const distJs = assetReference(distIndex, 'js');
const iosJs = assetReference(iosIndex, 'js');
const distCss = assetReference(distIndex, 'css');
const iosCss = assetReference(iosIndex, 'css');

if (distJs !== iosJs) {
  fail(`referenced JS differs: dist=${distJs}, ios=${iosJs}`);
}
if (distCss !== iosCss) {
  fail(`referenced CSS differs: dist=${distCss}, ios=${iosCss}`);
}

const distAssets = listFiles(join(distRoot, 'assets'));
const iosAssets = listFiles(join(iosPublicRoot, 'assets'));
if (JSON.stringify(distAssets) !== JSON.stringify(iosAssets)) {
  fail(`generated asset set differs: dist=${distAssets.length}, ios=${iosAssets.length}`);
}

const mismatchedAssets = distAssets.filter(asset => (
  sha256(join(distRoot, 'assets', asset)) !== sha256(join(iosPublicRoot, 'assets', asset))
));
if (mismatchedAssets.length > 0) {
  fail(`generated asset hashes differ: ${mismatchedAssets.join(', ')}`);
}

const sourceMaps = listFiles(iosPublicRoot).filter(path => path.endsWith('.map'));
if (sourceMaps.length > 0) {
  fail(`generated iOS public contains sourcemaps: ${sourceMaps.join(', ')}`);
}

const generatedMainJs = readFileSync(join(iosPublicRoot, iosJs), 'utf8');
for (const safeguard of requiredSafeguards) {
  if (!generatedMainJs.includes(safeguard)) {
    fail(`generated main JS is missing safeguard: ${safeguard}`);
  }
}

const result = {
  index_sha256: requireExactFile(join(distRoot, 'index.html'), join(iosPublicRoot, 'index.html'), 'index.html'),
  main_js: {
    path: distJs,
    sha256: requireExactFile(join(distRoot, distJs), join(iosPublicRoot, iosJs), 'main JS'),
  },
  css: {
    path: distCss,
    sha256: requireExactFile(join(distRoot, distCss), join(iosPublicRoot, iosCss), 'CSS'),
  },
  asset_count: distAssets.length,
  sourcemap_count: sourceMaps.length,
  safeguards: requiredSafeguards,
};

console.log(JSON.stringify(result, null, 2));
