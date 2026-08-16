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
  material_type?: 'personal' | 'cet4' | 'cet6' | 'postgraduate_exam';
  exam_year?: number | null;
  exam_set?: number | null;
  content_version?: string;
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

export type QueuedActionType = 'sense_review.rating' | 'reading_session.interaction';

interface QueuedActionBase {
  client_action_id: string;
  type: QueuedActionType;
  occurred_at: string;
  sequence: number;
}

export interface QueuedRatingAction extends QueuedActionBase {
  type: 'sense_review.rating';
  payload: {
    review_card_id: number;
    rating: ReviewRating;
    review_duration_ms: number;
    reading_session_id?: string;
    occurrence_id?: string;
  };
}

export interface QueuedReadingInteractionAction extends QueuedActionBase {
  type: 'reading_session.interaction';
  payload: {
    reading_session_id: string;
    interaction_type: 'opened' | 'helped';
    occurrence_id: string;
  };
}

export type QueuedAction = QueuedRatingAction | QueuedReadingInteractionAction;

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

export interface ArticleSentenceTranslation {
  sentence_index: number | string;
  source_text: string;
  translation_zh: string;
}

export interface ArticleSenseSummary {
  occurrence_id: number;
  word_sense_id: number;
  word_sense_version: string | null;
  source_sentence_identity: number | string | null;
  lemma: string;
  pos: string | null;
  sense_zh: string;
  sense_en: string | null;
}

export interface ChapterPackage {
  content_version: string;
  dictionary_version: string;
  tokens: ReaderToken[];
  sentence_translations: ArticleSentenceTranslation[];
  sense_summaries: ArticleSenseSummary[];
  dictionary_summaries: Record<string, string[]>;
  reading_session?: ReadingSessionProjection;
}

export interface ReadingSenseCandidate {
  word_sense_id: number;
  review_card_id: number | null;
  sense_zh: string | null;
  sense_en: string | null;
  pos: string | null;
}

export interface ReadingTarget {
  occurrence_id: string;
  kind: string;
  start_word_index: number;
  end_word_index: number;
  candidate_word_senses: ReadingSenseCandidate[];
}

export interface ReadingSessionProjection {
  reading_session_id: string;
  chapter_id: number;
  source_revision: string;
  status: string;
  completed: boolean;
  reading_targets: ReadingTarget[];
}

export interface ReadingFinishProjection {
  completed: boolean;
  can_commit: boolean;
  settlement_mode: 'preflight' | 'commit';
  passive_good_count: number;
  unresolved_count: number;
  excluded_count: number;
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
