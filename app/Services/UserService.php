<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Support\Str;

use App\Services\GoalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class UserService {
    /**
     * Child-first ownership map for rows that belong to one user + study language.
     * The H-05 schema contract fails when a new user/language table is added without
     * being assigned here.
     *
     * @var array<string,list<string>>
     */
    public const LANGUAGE_SCOPED_USER_TABLES = [
        'media_references' => ['language_id'],
        'operations' => ['language_id'],
        'reading_session_card_settlements' => ['language_id'],
        'reading_session_completions' => ['language_id'],
        'reading_session_interactions' => ['language_id'],
        'reading_occurrence_sense_evidence' => ['language_id'],
        'reading_unfamiliar_targets' => ['language_id'],
        'reading_progress' => ['language_id'],
        'reading_inline_sense_confirmations' => ['language'],
        'reading_sessions' => ['language_id'],
        'reschedule_snapshots' => ['language_id'],
        'special_study_sessions' => ['language_id'],
        'legacy_word_card_migration_items' => ['language_id'],
        'review_card_state_events' => ['language_id'],
        'review_card_saved_searches' => ['language_id'],
        'review_daily_limit_overrides' => ['language_id'],
        'review_setting_preset_bindings' => ['language_id'],
        'knowledge_hygiene_operations' => ['language_id'],
        'word_sense_tags' => ['language_id'],
        'review_logs' => ['language', 'language_id'],
        'review_cards' => ['language', 'language_id'],
        // WordSense may restrict deletion of its learning-source occurrence.
        'word_senses' => ['language', 'language_id'],
        'word_sense_occurrences' => ['language', 'language_id'],
        'ai_study_card_pending_items' => ['language', 'language_id'],
        'chapter_ai_reading_assists' => ['language'],
        'daily_achivements' => ['language'],
        'goal_achievements' => ['language'],
        'goals' => ['language'],
        'example_sentences' => ['language'],
        'phrases' => ['language'],
        'encountered_words' => ['language'],
        'chapters' => ['language'],
        'books' => ['language'],
        'user_study_base_rules' => ['language'],
        // References are deleted first. Non-shared files are quarantined before
        // the transaction so a database rollback can restore them safely.
        'media_assets' => ['language_id'],
    ];

    public const ACCOUNT_LEGACY_USER_TABLES = [
        'media_references',
        'media_assets',
        'reading_session_completions',
        'reading_session_card_settlements',
        'reading_session_interactions',
        'reading_sessions',
        'reading_occurrence_sense_evidence',
        'reading_unfamiliar_targets',
        'reading_progress',
        'reading_inline_sense_confirmations',
        'review_card_state_events',
        'review_card_saved_searches',
        'review_logs',
        'review_cards',
        'word_senses',
        'word_sense_occurrences',
        'ai_study_card_pending_items',
        'chapter_ai_reading_assists',
        'queue_stats_chapter_processing',
        'daily_achivements',
        'goal_achievements',
        'goals',
        'example_sentences',
        'phrases',
        'encountered_words',
        'chapters',
        'books',
        'user_study_base_rules',
        'settings',
        'legacy_word_card_migration_items',
    ];

    private LegacyWordCardMigrationProtectionService $legacyWordCardMigrationProtectionService;

    public function __construct(?LegacyWordCardMigrationProtectionService $legacyWordCardMigrationProtectionService = null) {
        $this->legacyWordCardMigrationProtectionService = $legacyWordCardMigrationProtectionService
            ?? app(LegacyWordCardMigrationProtectionService::class);
    }

    public function getUsers($userId) {
        $users = User
            ::select(['id', 'name', 'email', 'is_admin', 'password_changed', 'created_at'])
            ->get();

        foreach ($users as $user) {
            $user->created_at_text = Carbon::parse($user->created_at)->format('Y-m-d');
            $user->is_current_user = $user->id === $userId;
        }

        return $users;
    }

    public function updatePassword($user, $password) {
        $user->password = Hash::make($password);
        $user->password_changed = true;
        $user->save();
    }

    public function createUser($name, $email, $password, $isAdmin, $passwordChanged, string $studyLanguage = 'english') {
        // check for duplicated e-email address
        $user = User
            ::where('email', $email)
            ->first();

        if ($user) {
            throw new \Exception('An other already exists with this email address.');
        }

        // create user
        $user = new User();
        $user->name = $name;
        $user->email = $email;
        $user->is_admin = $isAdmin;
        $user->password_changed = $passwordChanged;
        $user->selected_language = $studyLanguage;
        $user->uuid = Str::uuid()->toString();
        $user->password = Hash::make($password);
        $user->save();

        (new GoalService())->createGoalsForLanguage($user->id, $studyLanguage);
        app(SettingsService::class)->updateUserSettings($user->id, [
            'uiLanguage' => 'zh-CN',
        ]);

        return true;
    }

    public function updateUser($userId, $name, $email, $isAdmin) {
        // check for duplicated e-email address
        $user = User
            ::where('email', $email)
            ->where('id', '<>', $userId)
            ->first();

        if ($user) {
            throw new \Exception('An other user already exists with this email address.');
        }

        // check if user can be set to not admin
        if (!$isAdmin) {
            $otherAdminAccounts = User
                ::where('id', '<>', $userId)
                ->where('is_admin', true)
                ->count();

            if ($otherAdminAccounts === 0) {
                throw new \DomainException('The system must keep at least one administrator account.');
            }
        }

        // retrieve user
        $user = User
            ::where('id', $userId)
            ->first();

        if (!$user) {
            throw new \Exception('This user does not exist.');
        }
        
        // update user
        $user->name = $name;
        $user->email = $email;
        $user->is_admin = $isAdmin;
        $user->save();

        return true;
    }

    public function deleteAccount(User $user): void
    {
        $userId = (int) $user->id;
        $email = (string) $user->email;
        $disk = Storage::disk((string) config('media.disk'));
        $mediaDirectory = 'user-'.$userId;
        $quarantine = $this->quarantineMediaPaths($disk, $disk->allFiles($mediaDirectory));

        try {
            DB::transaction(function () use ($userId, $email) {
                $adminIds = User::query()
                    ->where('is_admin', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');
                $lockedUser = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

                if ((bool) $lockedUser->is_admin && $adminIds->count() <= 1) {
                    throw new \DomainException('The system must keep at least one administrator account.');
                }

                $lockedUser->tokens()->delete();

                if (Schema::hasTable('password_resets')) {
                    DB::table('password_resets')->where('email', $email)->delete();
                }

                $sessionTable = (string) config('session.table', 'sessions');
                if (Schema::hasTable($sessionTable) && Schema::hasColumn($sessionTable, 'user_id')) {
                    DB::table($sessionTable)->where('user_id', $userId)->delete();
                }

                foreach (self::ACCOUNT_LEGACY_USER_TABLES as $table) {
                    DB::table($table)->where('user_id', $userId)->delete();
                }

                DB::table('legacy_word_card_migration_runs')
                    ->where('filters->user_id', $userId)
                    ->delete();

                $lockedUser->delete();
            });
        } catch (\Throwable $error) {
            $this->restoreQuarantinedMedia($disk, $quarantine, $error);
            throw $error;
        }

        if ($disk->exists($mediaDirectory) && ! $disk->deleteDirectory($mediaDirectory)) {
            report(new \RuntimeException('Deleted account media directory could not be removed.'));
        }
        $this->purgeMediaQuarantine($disk, $quarantine);
    }

    public function deleteUserLanguageData($userId, $language): void
    {
        $userId = (int) $userId;
        $language = (string) $language;
        $this->legacyWordCardMigrationProtectionService->assertScopeMutable($userId, $language);

        $disk = Storage::disk((string) config('media.disk'));
        $mediaFiles = array_map(
            fn (string $storageName): string => 'user-'.$userId.'/'.$storageName,
            $this->languageMediaFilesToDelete($userId, $language),
        );
        $quarantine = $this->quarantineMediaPaths($disk, $mediaFiles);

        try {
            DB::transaction(function () use ($userId, $language) {
                foreach (self::LANGUAGE_SCOPED_USER_TABLES as $table => $languageColumns) {
                    $this->deleteLanguageScopedRows($table, $languageColumns, $userId, $language);
                }

                DB::table('legacy_word_card_migration_runs')
                    ->where('filters->user_id', $userId)
                    ->where('filters->language', $language)
                    ->delete();
            });
        } catch (\Throwable $error) {
            $this->restoreQuarantinedMedia($disk, $quarantine, $error);
            throw $error;
        }

        $this->purgeMediaQuarantine($disk, $quarantine);
    }

    /** @param list<string> $languageColumns */
    private function deleteLanguageScopedRows(
        string $table,
        array $languageColumns,
        int $userId,
        string $language,
    ): void {
        DB::table($table)
            ->where('user_id', $userId)
            ->where(function ($query) use ($languageColumns, $language) {
                foreach ($languageColumns as $index => $column) {
                    $index === 0
                        ? $query->where($column, $language)
                        : $query->orWhere($column, $language);
                }
            })
            ->delete();
    }

    /** @return list<string> */
    private function languageMediaFilesToDelete(int $userId, string $language): array
    {
        $storageNames = DB::table('media_assets')
            ->where('user_id', $userId)
            ->where('language_id', $language)
            ->pluck('storage_name')
            ->filter(fn ($name): bool => is_string($name) && $name !== '')
            ->unique()
            ->values();

        if ($storageNames->isEmpty()) {
            return [];
        }

        $sharedNames = DB::table('media_assets')
            ->where('user_id', $userId)
            ->whereIn('storage_name', $storageNames->all())
            ->where(function ($query) use ($language) {
                $query->whereNull('language_id')->orWhere('language_id', '<>', $language);
            })
            ->pluck('storage_name')
            ->all();

        return $storageNames->diff($sharedNames)->values()->all();
    }

    /**
     * @param list<string> $paths
     * @return array{directory:?string,files:array<string,string>}
     */
    private function quarantineMediaPaths($disk, array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            $paths,
            fn ($path): bool => is_string($path) && $path !== '',
        )));
        if ($paths === []) {
            return ['directory' => null, 'files' => []];
        }
        sort($paths, SORT_STRING);

        $directory = '.deletion-quarantine/'.Str::uuid();
        $moved = [];
        foreach ($paths as $source) {
            if (! $disk->exists($source)) {
                continue;
            }

            $destination = $directory.'/'.$source;
            if (! $disk->move($source, $destination)) {
                $this->restoreQuarantinedMedia(
                    $disk,
                    ['directory' => $directory, 'files' => $moved],
                    new \RuntimeException('Media could not be moved into deletion quarantine.'),
                );
                throw new \RuntimeException('Media could not be moved into deletion quarantine.');
            }
            $moved[$source] = $destination;
        }

        return ['directory' => $directory, 'files' => $moved];
    }

    /** @param array{directory:?string,files:array<string,string>} $quarantine */
    private function restoreQuarantinedMedia($disk, array $quarantine, \Throwable $originalError): void
    {
        foreach (array_reverse($quarantine['files'], true) as $source => $quarantined) {
            if ($disk->exists($quarantined) && ! $disk->move($quarantined, $source)) {
                report($originalError);
                throw new \RuntimeException(
                    'Media quarantine could not be restored after deletion failed.',
                    0,
                    $originalError,
                );
            }
        }

        $directory = $quarantine['directory'];
        if ($directory !== null && $disk->exists($directory) && ! $disk->deleteDirectory($directory)) {
            report(new \RuntimeException('Restored media left an empty deletion quarantine directory.'));
        }
    }

    /** @param array{directory:?string,files:array<string,string>} $quarantine */
    private function purgeMediaQuarantine($disk, array $quarantine): void
    {
        $directory = $quarantine['directory'];
        if ($directory !== null && $disk->exists($directory) && ! $disk->deleteDirectory($directory)) {
            report(new \RuntimeException('Committed deletion left private media quarantine residue.'));
        }
    }
}
