import { Capacitor, registerPlugin } from '@capacitor/core';
import { LocalNotifications } from '@capacitor/local-notifications';
import { Preferences } from '@capacitor/preferences';
import type { Bootstrap } from './types';

interface SecureTokenPlugin {
  save(options: { token: string }): Promise<void>;
  load(): Promise<{ token: string | null }>;
  clear(): Promise<void>;
}

const SecureToken = registerPlugin<SecureTokenPlugin>('SecureToken');
const WEB_TOKEN_KEY = 'linguacafe-session-token';
const CACHED_BOOTSTRAP_KEY = 'cached_bootstrap_v1';

export function usesNativeSecureToken(platform: string): boolean {
  return platform === 'android' || platform === 'ios';
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function parseCachedBootstrap(value: string | null): Bootstrap | null {
  if (!value) return null;
  try {
    const parsed: unknown = JSON.parse(value);
    if (!isRecord(parsed) || !isRecord(parsed.user) || !isRecord(parsed.device)) return null;
    if (
      !Number.isInteger(parsed.user.id)
      || Number(parsed.user.id) <= 0
      || typeof parsed.user.name !== 'string'
      || typeof parsed.user.email !== 'string'
      || typeof parsed.current_language !== 'string'
      || !parsed.current_language
      || typeof parsed.device.device_uuid !== 'string'
      || !parsed.device.device_uuid
      || !isRecord(parsed.capabilities)
      || Object.values(parsed.capabilities).some(capability => typeof capability !== 'boolean')
    ) return null;
    return parsed as unknown as Bootstrap;
  } catch {
    return null;
  }
}

export async function getOrCreateDeviceUuid(): Promise<string> {
  const current = await Preferences.get({ key: 'device_uuid' });
  if (current.value) return current.value;
  const value = crypto.randomUUID();
  await Preferences.set({ key: 'device_uuid', value });
  return value;
}

export async function saveServerUrl(serverUrl: string): Promise<void> {
  await Preferences.set({ key: 'server_url', value: serverUrl });
}

export async function loadServerUrl(): Promise<string> {
  return (await Preferences.get({ key: 'server_url' })).value ?? '';
}

export async function saveToken(token: string): Promise<void> {
  if (usesNativeSecureToken(Capacitor.getPlatform())) {
    await SecureToken.save({ token });
    return;
  }
  sessionStorage.setItem(WEB_TOKEN_KEY, token);
}

export async function loadToken(): Promise<string | null> {
  if (usesNativeSecureToken(Capacitor.getPlatform())) {
    return (await SecureToken.load()).token;
  }
  return sessionStorage.getItem(WEB_TOKEN_KEY);
}

export async function clearToken(): Promise<void> {
  if (usesNativeSecureToken(Capacitor.getPlatform())) {
    await SecureToken.clear();
  } else {
    sessionStorage.removeItem(WEB_TOKEN_KEY);
  }
  await Preferences.remove({ key: CACHED_BOOTSTRAP_KEY });
}

export async function saveCachedBootstrap(bootstrap: Bootstrap): Promise<void> {
  await Preferences.set({ key: CACHED_BOOTSTRAP_KEY, value: JSON.stringify(bootstrap) });
}

export async function loadCachedBootstrap(): Promise<Bootstrap | null> {
  return parseCachedBootstrap((await Preferences.get({ key: CACHED_BOOTSTRAP_KEY })).value);
}

export async function scheduleDailyReminder(hour: number): Promise<void> {
  const permission = await LocalNotifications.requestPermissions();
  if (permission.display !== 'granted') {
    throw new Error('未获得通知权限');
  }
  await LocalNotifications.cancel({ notifications: [{ id: 7001 }] });
  await LocalNotifications.schedule({
    notifications: [{
      id: 7001,
      title: 'LinguaCafe 复习时间',
      body: '用几分钟巩固今天的词义。',
      schedule: { on: { hour, minute: 0 }, repeats: true, allowWhileIdle: true },
      smallIcon: 'ic_stat_linguacafe',
    }],
  });
  await Preferences.set({ key: 'reminder_hour', value: String(hour) });
}

export async function loadReminderHour(): Promise<number> {
  const value = Number((await Preferences.get({ key: 'reminder_hour' })).value);
  return Number.isInteger(value) && value >= 0 && value <= 23 ? value : 20;
}
