import { describe, expect, it } from 'vitest';
import { movedBeyondReaderTap, readerPhrase } from './readerTouchSelection';
import type { ReaderToken } from './types';

const token = (position: number, word: string, sentence: number, spaceAfter = true): ReaderToken => ({
  position,
  word,
  lemma: word.toLowerCase(),
  pos: 'noun',
  source_sentence_identity: sentence,
  is_structure: false,
  space_after: spaceAfter,
});

describe('reader touch selection', () => {
  it('keeps small movement as a tap and releases scrolling after the threshold', () => {
    expect(movedBeyondReaderTap(10, 10, 16, 16)).toBe(false);
    expect(movedBeyondReaderTap(10, 10, 21, 10)).toBe(true);
  });

  it('builds a forward or reverse phrase in source order', () => {
    const tokens = [token(1, 'New', 4), token(2, 'York', 4, false)];
    expect(readerPhrase(tokens, 0, 1)?.word).toBe('New York');
    expect(readerPhrase(tokens, 1, 0)?.lemma).toBe('new york');
    expect(readerPhrase(tokens, 0, 1)?.pos).toBe('phrase');
    expect(readerPhrase(tokens, 0, 1)?.selection_kind).toBe('phrase');
  });

  it('keeps a single token and rejects cross-sentence phrases', () => {
    const first = token(1, 'One', 4);
    expect(readerPhrase([first], 0, 0)).toBe(first);
    expect(readerPhrase([first, token(2, 'Two', 5)], 0, 1)).toBeNull();
  });
});
