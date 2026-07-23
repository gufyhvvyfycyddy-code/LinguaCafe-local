<?php

namespace App\Services;

use App\Models\User;

class AiStudyCardPendingItemService
{
    private AiStudyCardPendingLifecycleService $pendingLifecycleService;
    private AiStudyCardCandidatePackageService $candidatePackageService;
    private AiStudyCardGenerationService $generationService;

    public function __construct(private WordSenseService $wordSenseService)
    {
        $this->pendingLifecycleService = new AiStudyCardPendingLifecycleService();
        $this->candidatePackageService = new AiStudyCardCandidatePackageService();
        $candidateValidationService = new AiStudyCardCandidateValidationService();
        $sourceBindingService = new AiStudyCardSourceBindingService();
        $this->generationService = new AiStudyCardGenerationService(
            $this->wordSenseService,
            $candidateValidationService,
            $sourceBindingService,
            $this->pendingLifecycleService
        );
    }

    public function createOrGetPending(User $user, array $data): array
    {
        return $this->pendingLifecycleService->createOrGetPending($user, $data);
    }

    public function listPending(User $user, ?int $chapterId = null, string $statusFilter = 'pending'): array
    {
        return $this->pendingLifecycleService->listPending($user, $chapterId, $statusFilter);
    }

    public function buildPreviewPackage(User $user, array $itemIds): array
    {
        return $this->candidatePackageService->buildPreviewPackage($user, $itemIds);
    }

    public function buildFinalCandidatesPackage(User $user, array $payload): array
    {
        return $this->candidatePackageService->buildFinalCandidatesPackage($user, $payload);
    }

    public function dismiss(User $user, int $itemId): array
    {
        return $this->pendingLifecycleService->dismiss($user, $itemId);
    }

    public function restore(User $user, int $itemId): array
    {
        return $this->pendingLifecycleService->restore($user, $itemId);
    }

    public function generateCardsFromConfirmedCandidates(User $user, array $confirmedItems, array $finalCandidatesPackage): array
    {
        return $this->generationService->generate($user, $confirmedItems, $finalCandidatesPackage);
    }
}
