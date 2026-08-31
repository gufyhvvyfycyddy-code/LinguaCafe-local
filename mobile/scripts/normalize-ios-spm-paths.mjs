import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageDeclarationPattern = /\.package\([\s\S]*?\)/g;
const localPackagePattern = /(\.package\(\s*name:\s*"[^"]+"\s*,\s*path:\s*")([^"]+)("\s*\))/g;
const supportedLocalPackagePattern = /^\.package\(\s*name:\s*"[^"]+"\s*,\s*path:\s*"[^"]+"\s*\)$/;
const expectedPrefix = '../../../node_modules/';

function safeLocalPackagePath(rawPath) {
  const normalized = rawPath.replaceAll('\\', '/');
  const absolute = /^[A-Za-z]:[\\/]/.test(rawPath) || /^[\\/]/.test(rawPath);
  const remainder = normalized.startsWith(expectedPrefix)
    ? normalized.slice(expectedPrefix.length)
    : '';
  const invalidSegment = !remainder || remainder.split('/').some(segment => (
    segment === '' || segment === '.' || segment === '..'
  ));

  if (absolute || !normalized.startsWith(expectedPrefix) || invalidSegment) {
    throw new Error(`[normalize-ios-spm-paths] unsafe local package path: ${rawPath}`);
  }

  return normalized;
}

export function normalizeIosSpmLocalPackagePaths(source) {
  for (const declaration of source.match(packageDeclarationPattern) ?? []) {
    if (declaration.includes('path:') && !supportedLocalPackagePattern.test(declaration)) {
      throw new Error(`[normalize-ios-spm-paths] unsupported local package declaration: ${declaration.trim()}`);
    }
  }

  return source.replace(localPackagePattern, (_match, prefix, rawPath, suffix) => (
    `${prefix}${safeLocalPackagePath(rawPath)}${suffix}`
  ));
}

function run() {
  const mobileRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
  const packageSwiftPath = join(mobileRoot, 'ios', 'App', 'CapApp-SPM', 'Package.swift');
  const original = readFileSync(packageSwiftPath, 'utf8');
  const normalized = normalizeIosSpmLocalPackagePaths(original);

  if (normalized !== original) {
    writeFileSync(packageSwiftPath, normalized, 'utf8');
    console.log('[normalize-ios-spm-paths] normalized SwiftPM local package paths.');
  } else {
    console.log('[normalize-ios-spm-paths] SwiftPM local package paths already portable.');
  }
}

if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  run();
}
