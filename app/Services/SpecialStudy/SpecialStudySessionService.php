<?php

namespace App\Services\SpecialStudy;

use App\Exceptions\SpecialStudyException;
use App\Models\ReviewCard;
use App\Models\SpecialStudySession;
use App\Services\SenseReviewCardSerializerService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SpecialStudySessionService
{
    public function __construct(
        private readonly SpecialStudyCandidateQueryService $candidateQueryService,
        private readonly SenseReviewCardSerializerService $serializerService,
    ) {
    }

    public function create(
        array $input,
        int $userId,
        string $language,
        ?Carbon $now = null,
    ): array {
        $now ??= Carbon::now();
        $criteria = SpecialStudyCriteria::fromArray($input);
        [$name, $normalizedName] = $this->normalizeName($input['name'] ?? null);
        $candidates = $this->candidateQueryService->build(
            $criteria,
            $userId,
            $language,
            $now,
        );
        $orderedIds = $candidates['ordered_ids'];
        $completed = $orderedIds === [];

        try {
            $session = SpecialStudySession::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'language_id' => $language,
                'name' => $name,
                'normalized_name' => $normalizedName,
                'execution_mode' => $criteria->get('execution_mode'),
                'scenario' => $criteria->get('scenario'),
                'definition' => $criteria->toArray(),
                'ordered_card_ids' => $orderedIds,
                'remaining_card_ids' => $orderedIds,
                'completed_card_ids' => [],
                'skipped_card_ids' => [],
                'total_candidates' => $candidates['total_candidates'],
                'revision' => 1,
                'status' => $completed
                    ? SpecialStudySession::STATUS_COMPLETED
                    : SpecialStudySession::STATUS_ACTIVE,
                'completed_at' => $completed ? $now : null,
            ]);
        } catch (QueryException $exception) {
            $this->throwNameConflict($exception);
            throw $exception;
        }

        return $this->present($session);
    }

    public function listSaved(int $userId, string $language): array
    {
        return SpecialStudySession::query()
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->whereNotNull('normalized_name')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (SpecialStudySession $session) =>
                $this->presentSummary($session)
            )
            ->values()
            ->all();
    }

    public function show(
        string $sessionId,
        int $userId,
        string $language,
    ): array {
        return $this->present(
            $this->findScoped($sessionId, $userId, $language),
        );
    }

    public function save(
        string $sessionId,
        int $userId,
        string $language,
        string $name,
        int $expectedRevision,
    ): array {
        [$name, $normalizedName] = $this->normalizeName($name, true);

        try {
            $session = DB::transaction(function () use (
                $sessionId,
                $userId,
                $language,
                $name,
                $normalizedName,
                $expectedRevision,
            ) {
                $session = $this->findScoped(
                    $sessionId,
                    $userId,
                    $language,
                    true,
                );
                $this->assertRevision($session, $expectedRevision);
                $session->forceFill([
                    'name' => $name,
                    'normalized_name' => $normalizedName,
                    'revision' => $session->revision + 1,
                ])->save();

                return $session->fresh();
            }, 3);
        } catch (QueryException $exception) {
            $this->throwNameConflict($exception);
            throw $exception;
        }

        return $this->present($session);
    }

    public function rebuild(
        string $sessionId,
        int $userId,
        string $language,
        int $expectedRevision,
        ?Carbon $now = null,
    ): array {
        $now ??= Carbon::now();

        $session = DB::transaction(function () use (
            $sessionId,
            $userId,
            $language,
            $expectedRevision,
            $now,
        ) {
            $session = $this->findScoped(
                $sessionId,
                $userId,
                $language,
                true,
            );
            $this->assertRevision($session, $expectedRevision);

            if ($session->status === SpecialStudySession::STATUS_ENDED
                && $session->normalized_name === null) {
                throw new SpecialStudyException(
                    'ended_unsaved',
                    'An ended unsaved Special Study session cannot be rebuilt.',
                    409,
                );
            }

            $criteria = SpecialStudyCriteria::fromArray($session->definition);
            $candidates = $this->candidateQueryService->build(
                $criteria,
                $userId,
                $language,
                $now,
            );
            $orderedIds = $candidates['ordered_ids'];
            $completed = $orderedIds === [];
            $session->forceFill([
                'ordered_card_ids' => $orderedIds,
                'remaining_card_ids' => $orderedIds,
                'completed_card_ids' => [],
                'skipped_card_ids' => [],
                'total_candidates' => $candidates['total_candidates'],
                'revision' => $session->revision + 1,
                'status' => $completed
                    ? SpecialStudySession::STATUS_COMPLETED
                    : SpecialStudySession::STATUS_ACTIVE,
                'completed_at' => $completed ? $now : null,
                'ended_at' => null,
            ])->save();

            return $session->fresh();
        }, 3);

        return $this->present($session);
    }

    public function end(
        string $sessionId,
        int $userId,
        string $language,
        int $expectedRevision,
        ?Carbon $now = null,
    ): array {
        $now ??= Carbon::now();

        $session = DB::transaction(function () use (
            $sessionId,
            $userId,
            $language,
            $expectedRevision,
            $now,
        ) {
            $session = $this->findScoped(
                $sessionId,
                $userId,
                $language,
                true,
            );
            $this->assertRevision($session, $expectedRevision);

            if ($session->status === SpecialStudySession::STATUS_ENDED) {
                throw new SpecialStudyException(
                    'already_ended',
                    'The Special Study session has already ended.',
                    409,
                );
            }

            $session->forceFill([
                'status' => SpecialStudySession::STATUS_ENDED,
                'revision' => $session->revision + 1,
                'ended_at' => $now,
            ])->save();

            return $session->fresh();
        }, 3);

        return $this->present($session);
    }

    public function present(SpecialStudySession $session): array
    {
        $remainingIds = array_values($session->remaining_card_ids ?? []);
        $currentCardId = $remainingIds[0] ?? null;
        $card = $currentCardId === null
            ? null
            : ReviewCard::query()
                ->with('sense')
                ->whereKey($currentCardId)
                ->where('user_id', $session->user_id)
                ->where('language_id', $session->language_id)
                ->where('target_type', ReviewCard::TARGET_SENSE)
                ->first();

        return [
            ...$this->presentSummary($session),
            'current_card' => $card
                ? $this->serializerService->serialize($card)
                : null,
        ];
    }

    private function presentSummary(SpecialStudySession $session): array
    {
        $remainingIds = array_values($session->remaining_card_ids ?? []);

        return [
            'id' => $session->id,
            'name' => $session->name,
            'saved' => $session->normalized_name !== null,
            'execution_mode' => $session->execution_mode,
            'scenario' => $session->scenario,
            'definition' => $session->definition,
            'status' => $session->status,
            'revision' => (int) $session->revision,
            'total_candidates' => (int) $session->total_candidates,
            'total_count' => count($session->ordered_card_ids ?? []),
            'completed_count' => count($session->completed_card_ids ?? []),
            'remaining_count' => count($remainingIds),
            'skipped_count' => count($session->skipped_card_ids ?? []),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];
    }

    private function findScoped(
        string $sessionId,
        int $userId,
        string $language,
        bool $lock = false,
    ): SpecialStudySession {
        $query = SpecialStudySession::query()
            ->whereKey($sessionId)
            ->where('user_id', $userId)
            ->where('language_id', $language);
        if ($lock) {
            $query->lockForUpdate();
        }
        $session = $query->first();

        if (! $session) {
            throw new SpecialStudyException(
                'session_not_found',
                'The Special Study session does not exist.',
                404,
            );
        }

        return $session;
    }

    private function assertRevision(
        SpecialStudySession $session,
        int $expectedRevision,
    ): void {
        if ((int) $session->revision !== $expectedRevision) {
            throw new SpecialStudyException(
                'revision_conflict',
                'The Special Study session has changed.',
                409,
                'expected_revision',
            );
        }
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function normalizeName(mixed $name, bool $required = false): array
    {
        if ($name === null && ! $required) {
            return [null, null];
        }
        if (! is_string($name)) {
            throw new SpecialStudyException(
                'invalid_type',
                'Special Study name must be a string.',
                422,
                'name',
            );
        }

        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new SpecialStudyException(
                'invalid_length',
                'Special Study name must contain 1 to 100 characters.',
                422,
                'name',
            );
        }

        return [$name, mb_strtolower($name)];
    }

    private function throwNameConflict(QueryException $exception): void
    {
        $driverCode = $exception->errorInfo[1] ?? null;
        if ($driverCode === 1062 || str_contains(
            strtolower($exception->getMessage()),
            'special_study_user_language_name_unique',
        )) {
            throw new SpecialStudyException(
                'name_conflict',
                'A saved Special Study session already uses that name.',
                409,
                'name',
            );
        }
    }
}
