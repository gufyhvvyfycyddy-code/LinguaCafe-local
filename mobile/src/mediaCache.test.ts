import { describe, expect, it } from 'vitest';
import { selectEvictions, sha256Hex } from './mediaCache';

describe('M18 media cache integrity', () => {
  it('evicts least recently used entries until the incoming blob fits', () => {
    expect(selectEvictions([
      { assetId: 'newer', sizeBytes: 4, lastAccessedAt: 20 },
      { assetId: 'oldest', sizeBytes: 5, lastAccessedAt: 10 },
    ], 10, 6)).toEqual(['oldest']);
  });

  it('computes stable sha256 for downloaded blobs', async () => {
    expect(await sha256Hex(new Blob(['audio']))).toBe(
      '6ed8919ce20490a5e3ad8630a4fab69475297abd07db73918dd5f36fcfaeb11b',
    );
  });
});
