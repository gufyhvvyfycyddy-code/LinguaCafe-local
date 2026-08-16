import type { ReaderToken } from './types';

export const READER_LONG_PRESS_MS = 450;
export const READER_SCROLL_CANCEL_PX = 10;

export function movedBeyondReaderTap(originX: number, originY: number, x: number, y: number): boolean {
  return Math.hypot(x - originX, y - originY) > READER_SCROLL_CANCEL_PX;
}

export function readerPhrase(tokens: ReaderToken[], startIndex: number, endIndex: number): ReaderToken | null {
  const start = Math.min(startIndex, endIndex);
  const end = Math.max(startIndex, endIndex);
  const selected = tokens.slice(start, end + 1);
  const first = selected[0];
  const last = selected.at(-1);
  if (!first || !last || selected.some(token => (
    token.is_structure
    || String(token.source_sentence_identity) !== String(first.source_sentence_identity)
  ))) return null;
  if (selected.length === 1) return first;

  const join = (value: (token: ReaderToken) => string) => selected
    .map(token => `${value(token)}${token.space_after ? ' ' : ''}`)
    .join('')
    .trim();

  return {
    position: first.position,
    word: join(token => token.word),
    lemma: join(token => token.lemma || token.word),
    pos: 'phrase',
    source_sentence_identity: first.source_sentence_identity,
    is_structure: false,
    space_after: last.space_after,
  };
}
