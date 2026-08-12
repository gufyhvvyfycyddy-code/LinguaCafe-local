<?php

namespace App\Services;

use App\Models\EncounteredWord;
use App\Models\LegacyWordCardMigrationItem;
use App\Models\LegacyWordCardMigrationRun;
use RuntimeException;

class LegacyWordCardMigrationProtectionService
{
    public function isEncounteredWordProtected(EncounteredWord $word): bool
    {
        return $this->protectedItemsQuery(
            (int) $word->user_id,
            (string) $word->language,
        )
            ->where('legacy_word_card_migration_items.encountered_word_id', (int) $word->id)
            ->exists();
    }

    public function isScopeProtected(int $userId, string $language): bool
    {
        return $this->protectedItemsQuery($userId, $language)->exists();
    }

    public function assertEncounteredWordMutable(EncounteredWord $word): void
    {
        if ($this->isEncounteredWordProtected($word)) {
            throw new RuntimeException(
                "EncounteredWord {$word->id} is protected by an applied legacy word-card migration.",
            );
        }
    }

    /** @param array<int> $encounteredWordIds */
    public function assertEncounteredWordIdsMutable(int $userId, string $language, array $encounteredWordIds): void
    {
        $ids = collect($encounteredWordIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $protectedId = $this->protectedItemsQuery($userId, $language)
            ->whereIn('legacy_word_card_migration_items.encountered_word_id', $ids)
            ->orderBy('legacy_word_card_migration_items.encountered_word_id')
            ->value('legacy_word_card_migration_items.encountered_word_id');

        if ($protectedId !== null) {
            throw new RuntimeException(
                "EncounteredWord {$protectedId} is protected by an applied legacy word-card migration.",
            );
        }
    }

    public function assertScopeMutable(int $userId, string $language): void
    {
        if ($this->isScopeProtected($userId, $language)) {
            throw new RuntimeException(
                "User/language scope {$userId}/{$language} is protected by an applied legacy word-card migration.",
            );
        }
    }

    private function protectedItemsQuery(int $userId, string $language)
    {
        return LegacyWordCardMigrationItem::query()
            ->join(
                'legacy_word_card_migration_runs',
                'legacy_word_card_migration_runs.id',
                '=',
                'legacy_word_card_migration_items.run_id',
            )
            ->where('legacy_word_card_migration_runs.state', LegacyWordCardMigrationRun::STATE_APPLIED)
            ->where('legacy_word_card_migration_items.user_id', $userId)
            ->where('legacy_word_card_migration_items.language_id', $language);
    }
}
