<?php

namespace App\Services;

use App\Models\ReviewCard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Throwable;

class MobileReviewPackageService
{
    public const SCHEMA_VERSION = 'mobile_download_package_v1';
    public const DEFAULT_HORIZON_DAYS = 7;
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;
    public const MAX_PACKAGE_BYTES = 2097152;

    public function __construct(
        private SenseReviewQueryService $queryService,
        private SenseReviewCardSerializerService $serializer,
        private WordSenseContentVersionService $wordSenseVersion,
    ) {
    }

    public function build(
        int $userId,
        string $language,
        int $horizonDays,
        int $limit,
        ?string $cursor,
    ): array {
        $state = $this->cursorState($cursor, $horizonDays, $userId, $language);
        $asOf = Carbon::parse($state['as_of'])->utc();
        $horizonEnd = $asOf->copy()->addDays($state['horizon_days']);

        $query = $this->queryService
            ->confirmedSenseCardQuery($userId, $language)
            ->senseReviewEligible($userId, $language, $asOf)
            ->whereNotNull('review_cards.fsrs_due_at')
            ->where('review_cards.fsrs_due_at', '<=', $horizonEnd)
            ->where('review_cards.created_at', '<=', $asOf)
            ->where('word_senses.created_at', '<=', $asOf)
            ->select('review_cards.*')
            ->with('sense')
            ->orderBy('review_cards.fsrs_due_at')
            ->orderBy('review_cards.id');

        if ($state['last_due_at'] !== null) {
            $lastDue = Carbon::parse($state['last_due_at'])->utc();
            $lastId = $state['last_id'];
            $query->where(function ($nested) use ($lastDue, $lastId) {
                $nested->where('review_cards.fsrs_due_at', '>', $lastDue)
                    ->orWhere(function ($sameDue) use ($lastDue, $lastId) {
                        $sameDue->where('review_cards.fsrs_due_at', '=', $lastDue)
                            ->where('review_cards.id', '>', $lastId);
                    });
            });
        }

        $cards = $query->limit(min($limit, self::MAX_LIMIT) + 1)->get();
        $hasMore = $cards->count() > $limit;
        $pageCards = $cards->take($limit)->values();
        $serialized = collect($this->serializer->serializeMany($pageCards))
            ->keyBy('review_card_id');
        $items = $pageCards->map(
            fn (ReviewCard $card) => $this->projectCard(
                $card,
                $serialized->get($card->id, []),
            ),
        )->values()->all();
        $fittedItemCount = $this->fittedItemCount($items);
        if ($fittedItemCount === 0 && count($items) > 0) {
            throw new InvalidMobilePackageSourceException(
                'A review package item exceeds the mobile package payload limit.',
            );
        }
        if ($fittedItemCount < count($items)) {
            $items = array_slice($items, 0, $fittedItemCount);
            $pageCards = $pageCards->take($fittedItemCount)->values();
            $hasMore = true;
        }

        $nextCursor = null;
        if ($hasMore && $pageCards->isNotEmpty()) {
            $last = $pageCards->last();
            $nextCursor = $this->encodeCursor([
                'v' => 1,
                'type' => 'short_term_review',
                'user_id' => $userId,
                'language' => $language,
                'as_of' => $asOf->toIso8601String(),
                'horizon_days' => $state['horizon_days'],
                'last_due_at' => $last->fsrs_due_at?->utc()->toIso8601String(),
                'last_id' => $last->id,
            ]);
        }

        $packageVersion = hash('sha256', implode('|', [
            self::SCHEMA_VERSION,
            $userId,
            $language,
            $asOf->toIso8601String(),
            $state['horizon_days'],
        ]));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'package_type' => 'short_term_review',
            'package_version' => 'sha256:' . $packageVersion,
            'generated_at' => $asOf->toIso8601String(),
            'horizon_days' => $state['horizon_days'],
            'horizon_ends_at' => $horizonEnd->toIso8601String(),
            'ordering' => ['fsrs_due_at', 'review_card_id'],
            'read_only' => true,
            'offline_rating_upload_supported' => true,
            'items' => $items,
            'item_count' => count($items),
            'has_more' => $nextCursor !== null,
            'next_cursor' => $nextCursor,
        ];
    }

    private function projectCard(ReviewCard $card, array $payload): array
    {
        $media = array_values(array_filter(
            $payload['media'] ?? [],
            fn (array $item) => ($item['role'] ?? null) === 'word_pronunciation'
                || (($item['role'] ?? null) === 'example_audio'
                    && ($item['slot_key'] ?? null) === MediaManifestService::slotKey(
                        'example_audio',
                        $payload['example_sentence_en'] ?? null,
                    )),
        ));
        $display = [
            'word_sense_id' => $payload['word_sense_id'] ?? $card->target_id,
            'word_sense_version' => $this->wordSenseVersion->version($card->sense),
            'lemma' => $payload['lemma'] ?? null,
            'surface_form' => $payload['surface_form'] ?? null,
            'pos' => $payload['pos'] ?? null,
            'sense_zh' => $payload['sense_zh'] ?? null,
            'sense_en' => $payload['sense_en'] ?? null,
            'aliases_zh' => $payload['aliases_zh'] ?? [],
            'collocations' => $payload['collocations'] ?? [],
            'understanding_aid' => $payload['understanding_aid'] ?? [],
            'example_sentence_en' => $payload['example_sentence_en'] ?? null,
            'example_sentence_zh' => $payload['example_sentence_zh'] ?? null,
            'example_sentence_translation_source' => $payload['example_sentence_translation_source'] ?? null,
            'example_sentence_tokens' => $payload['example_sentence_tokens'] ?? null,
            'displayed_occurrence_id' => $payload['displayed_occurrence_id'] ?? null,
            'occurrence_count' => $payload['occurrence_count'] ?? 0,
            'example_source_status' => $payload['example_source_status'] ?? 'empty',
            'media' => $media,
        ];
        $scheduling = [
            'fsrs_state' => $card->fsrs_state,
            'fsrs_due_at' => $card->fsrs_due_at?->utc()->toIso8601String(),
            'fsrs_stability' => $card->fsrs_stability,
            'fsrs_difficulty' => $card->fsrs_difficulty,
            'fsrs_reps' => (int) $card->fsrs_reps,
            'fsrs_lapses' => (int) $card->fsrs_lapses,
            'fsrs_last_reviewed_at' => $card->fsrs_last_reviewed_at?->utc()->toIso8601String(),
            'fsrs_enabled' => (bool) $card->fsrs_enabled,
            'lifecycle_state' => $card->lifecycle_state,
            'lifecycle_version' => (int) $card->lifecycle_version,
        ];

        return [
            'review_card_id' => $card->id,
            'item_version' => 'sha256:' . hash('sha256', json_encode(
                [$display, $scheduling],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )),
            'display' => $display,
            'scheduling_snapshot' => $scheduling,
        ];
    }

    private function cursorState(
        ?string $cursor,
        int $requestedHorizon,
        int $userId,
        string $language,
    ): array {
        if ($cursor === null || $cursor === '') {
            return [
                'as_of' => Carbon::now('UTC')->toIso8601String(),
                'horizon_days' => $requestedHorizon,
                'last_due_at' => null,
                'last_id' => null,
            ];
        }

        try {
            $data = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidMobilePackageCursorException();
        }

        if (!is_array($data)
            || ($data['v'] ?? null) !== 1
            || ($data['type'] ?? null) !== 'short_term_review'
            || (int) ($data['user_id'] ?? 0) !== $userId
            || !hash_equals($language, (string) ($data['language'] ?? ''))
            || !is_string($data['as_of'] ?? null)
            || !is_int($data['horizon_days'] ?? null)
            || $data['horizon_days'] !== $requestedHorizon
            || !is_string($data['last_due_at'] ?? null)
            || !is_int($data['last_id'] ?? null)
            || $data['last_id'] < 1) {
            throw new InvalidMobilePackageCursorException();
        }

        try {
            Carbon::parse($data['as_of']);
            Carbon::parse($data['last_due_at']);
        } catch (Throwable) {
            throw new InvalidMobilePackageCursorException();
        }

        return $data;
    }

    private function encodeCursor(array $data): string
    {
        try {
            return Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new InvalidMobilePackageCursorException();
        }
    }

    private function fittedItemCount(array $items): int
    {
        $bytes = 0;
        foreach ($items as $index => $item) {
            $bytes += strlen(json_encode(
                $item,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            if ($bytes > self::MAX_PACKAGE_BYTES - 16384) {
                return $index;
            }
        }

        return count($items);
    }
}
