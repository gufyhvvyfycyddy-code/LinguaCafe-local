import type { MediaReference } from './types';

interface StoredMedia {
  assetId: string;
  sha256: string;
  mimeType: string;
  sizeBytes: number;
  lastAccessedAt: number;
  bytes: ArrayBuffer;
}

export interface CacheEntrySummary {
  assetId: string;
  sizeBytes: number;
  lastAccessedAt: number;
}

export function selectEvictions(
  entries: CacheEntrySummary[],
  maxBytes: number,
  incomingBytes: number,
): string[] {
  let total = entries.reduce((sum, entry) => sum + entry.sizeBytes, 0) + incomingBytes;
  if (total <= maxBytes) return [];
  const evicted: string[] = [];
  for (const entry of [...entries].sort((a, b) => a.lastAccessedAt - b.lastAccessedAt)) {
    evicted.push(entry.assetId);
    total -= entry.sizeBytes;
    if (total <= maxBytes) break;
  }
  return evicted;
}

export async function sha256Hex(blob: Blob): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
  return Array.from(new Uint8Array(digest))
    .map(byte => byte.toString(16).padStart(2, '0'))
    .join('');
}

export class MediaCache {
  private readonly databaseName = 'linguacafe-media-v1';
  private readonly databaseVersion = 2;

  constructor(private readonly maxBytes = 50 * 1024 * 1024) {}

  async get(reference: MediaReference): Promise<Blob | null> {
    const database = await this.open();
    const entry = await this.request<StoredMedia | undefined>(
      database.transaction('media', 'readonly').objectStore('media').get(reference.asset_id),
    );
    if (!entry) return null;
    const blob = new Blob([entry.bytes], { type: entry.mimeType });
    if (
      entry.sha256 !== reference.sha256
      || blob.size !== entry.sizeBytes
      || await sha256Hex(blob) !== reference.sha256
    ) {
      await this.delete(database, reference.asset_id);
      return null;
    }
    entry.lastAccessedAt = Date.now();
    await this.request(database.transaction('media', 'readwrite').objectStore('media').put(entry));
    return blob;
  }

  async put(reference: MediaReference, blob: Blob): Promise<void> {
    if (blob.size !== reference.size_bytes || await sha256Hex(blob) !== reference.sha256) {
      throw new Error('下载音频的完整性校验失败');
    }
    if (blob.size > this.maxBytes) throw new Error('音频超过离线缓存上限');
    const database = await this.open();
    const entries = await this.request<StoredMedia[]>(
      database.transaction('media', 'readonly').objectStore('media').getAll(),
    );
    const withoutCurrent = entries.filter(entry => entry.assetId !== reference.asset_id);
    for (const assetId of selectEvictions(withoutCurrent, this.maxBytes, blob.size)) {
      await this.delete(database, assetId);
    }
    const bytes = await blob.arrayBuffer();
    await this.request(database.transaction('media', 'readwrite').objectStore('media').put({
      assetId: reference.asset_id,
      sha256: reference.sha256,
      mimeType: reference.mime_type,
      sizeBytes: blob.size,
      lastAccessedAt: Date.now(),
      bytes,
    } satisfies StoredMedia));
  }

  async clear(): Promise<void> {
    const database = await this.open();
    await this.request(database.transaction('media', 'readwrite').objectStore('media').clear());
  }

  private open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(this.databaseName, this.databaseVersion);
      request.onupgradeneeded = () => {
        if (request.result.objectStoreNames.contains('media')) {
          request.result.deleteObjectStore('media');
        }
        request.result.createObjectStore('media', { keyPath: 'assetId' });
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('无法打开离线媒体缓存'));
    });
  }

  private delete(database: IDBDatabase, assetId: string): Promise<unknown> {
    return this.request(database.transaction('media', 'readwrite').objectStore('media').delete(assetId));
  }

  private request<T = unknown>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error ?? new Error('离线媒体缓存操作失败'));
    });
  }
}
