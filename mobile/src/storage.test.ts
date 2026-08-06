import { beforeEach, describe, expect, it, vi } from 'vitest';

const preferences = new Map<string, string>();

vi.mock('@capacitor/core', () => ({
  Capacitor: { getPlatform: () => 'web' },
  registerPlugin: () => ({}),
}));
vi.mock('@capacitor/local-notifications', () => ({ LocalNotifications: {} }));
vi.mock('@capacitor/preferences', () => ({
  Preferences: {
    get: vi.fn(async ({ key }: { key: string }) => ({ value: preferences.get(key) ?? null })),
    set: vi.fn(async ({ key, value }: { key: string; value: string }) => { preferences.set(key, value); }),
    remove: vi.fn(async ({ key }: { key: string }) => { preferences.delete(key); }),
  },
}));

import {
  loadCachedBootstrap,
  parseCachedBootstrap,
  saveCachedBootstrap,
  usesNativeSecureToken,
} from './storage';

const bootstrap = {
  user: { id: 544, name: 'Android Acceptance', email: 'android@example.test' },
  current_language: 'english',
  device: { device_uuid: 'device-uuid' },
  capabilities: { offline_review: true },
};

describe('cached bootstrap', () => {
  beforeEach(() => preferences.clear());

  it('round-trips the validated user and language scope', async () => {
    await saveCachedBootstrap(bootstrap);
    await expect(loadCachedBootstrap()).resolves.toEqual(bootstrap);
  });

  it('rejects malformed or incomplete cached scope data', () => {
    expect(parseCachedBootstrap('{bad json')).toBeNull();
    expect(parseCachedBootstrap(JSON.stringify({ ...bootstrap, current_language: '' }))).toBeNull();
    expect(parseCachedBootstrap(JSON.stringify({ ...bootstrap, user: { ...bootstrap.user, id: 0 } }))).toBeNull();
  });
});

describe('secure token platform routing', () => {
  it('uses native secure storage on both mobile shells only', () => {
    expect(usesNativeSecureToken('android')).toBe(true);
    expect(usesNativeSecureToken('ios')).toBe(true);
    expect(usesNativeSecureToken('web')).toBe(false);
  });
});
