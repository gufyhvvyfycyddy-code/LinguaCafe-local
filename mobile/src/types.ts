export interface MobileEnvelope<T> {
  success: boolean;
  data: T;
  meta: {
    server_time: string;
    schema_version: number;
    minimum_client_version: string;
  };
}

export interface MobileApiFailure {
  success: false;
  error: {
    code: string;
    message: string;
    details?: Record<string, string[]>;
  };
}

export interface Bootstrap {
  user: { id: number; name: string; email: string };
  current_language: string;
  device: { device_uuid: string; device_name?: string };
  capabilities: Record<string, boolean>;
}

export interface ArticleSummary {
  book_id: number;
  name: string;
  language: string;
  chapter_count: number;
}

export interface ChapterSummary {
  chapter_id: number;
  name: string;
  token_count: number;
}

export interface ReaderToken {
  position: number;
  word: string;
  lemma: string | null;
  pos: string | null;
  source_sentence_identity: number | string | null;
  is_structure: boolean;
  space_after: boolean;
}

export interface ReviewItem {
  review_card_id: number;
  display: {
    lemma: string;
    surface_form?: string;
    pos?: string;
    sense_zh?: string;
    sense_en?: string;
    example_sentence_en?: string;
    example_sentence_zh?: string;
    media?: MediaReference[];
  };
}

export type ReviewRating = 'again' | 'hard' | 'good' | 'easy';

export type QueuedActionType = 'sense_review.rating';

export interface QueuedAction {
  client_action_id: string;
  type: QueuedActionType;
  occurred_at: string;
  sequence: number;
  payload: {
    review_card_id: number;
    rating: ReviewRating;
    review_duration_ms: number;
  };
}

export interface SyncActionResult {
  client_action_id: string;
  outcome: 'applied' | 'replayed' | 'conflict' | 'rejected' | 'retryable';
  error: null | {
    code: string;
    message: string;
    retryable?: boolean;
  };
}

export interface SyncBatchResult {
  batch_id: string;
  status: 'completed' | 'partial' | 'failed';
  counts: {
    total: number;
    succeeded: number;
    failed: number;
    replayed: number;
  };
  results: SyncActionResult[];
}

export interface OfflineSyncIssue {
  client_action_id: string;
  code: string;
  message: string;
  recorded_at: string;
}

export interface MediaReference {
  reference_id: string;
  asset_id: string;
  role: 'word_pronunciation' | 'example_audio';
  slot_key: string;
  source_text?: string | null;
  sha256: string;
  mime_type: string;
  extension: 'mp3' | 'm4a';
  size_bytes: number;
  original_name: string;
  source_kind: string;
  copyright_status: string;
  copyright_source?: string | null;
}

export interface DailySummary {
  today: {
    reviewed_today_count: number;
    introduced_today_count: number;
  };
  active_card_count: number;
  due_now_count: number;
  generated_at: string;
}

export interface WordSenseSummary {
  sense_id: number;
  lemma: string;
  pos: string | null;
  sense_zh: string;
  sense_en: string | null;
  aliases_zh: string[];
  collocations: string[];
}
