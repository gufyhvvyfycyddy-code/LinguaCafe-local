<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

final class GoalIdentity
{
    public const FINGERPRINT_COLUMN = 'goal_identity_fingerprint';

    public const UNIQUE_INDEX = 'goals_identity_fingerprint_unique';

    public const DEFAULT_TYPES = ['review', 'read_words', 'learn_words'];

    public const TARGETED_TYPES = ['read_book_chapter'];

    public static function normalizeLanguage(string $language): string
    {
        return mb_strtolower(trim($language), 'UTF-8');
    }

    public static function normalizeType(string $type): string
    {
        return mb_strtolower(trim($type), 'UTF-8');
    }

    public static function normalizeTargetScope(?string $targetId): string
    {
        $target = trim((string) $targetId);

        if ($target === '') {
            return 'd:';
        }

        if (preg_match('/^[0-9]+$/', $target) === 1) {
            $normalized = ltrim($target, '0');

            return 'n:'.($normalized === '' ? '0' : $normalized);
        }

        return 's:'.$target;
    }

    public static function audit(
        ConnectionInterface $connection,
        string $goalsTable = 'goals',
        ?string $achievementsTable = 'goal_achievements',
    ): array {
        $goals = self::quotedIdentifier($goalsTable);
        $targetScope = self::targetScopeSql('target_id');
        $duplicate = $connection->selectOne(<<<SQL
SELECT
    COUNT(*) AS duplicate_identity_groups,
    COALESCE(SUM(identity_rows), 0) AS duplicate_identity_rows,
    COALESCE(SUM(
        CASE
            WHEN quantity_variants > 1 OR name_variants > 1 OR chapter_variants > 1 THEN 1
            ELSE 0
        END
    ), 0) AS conflict_identity_groups
FROM (
    SELECT
        user_id,
        LOWER(TRIM(language)) AS language_norm,
        LOWER(TRIM(type)) AS type_norm,
        BINARY ({$targetScope}) AS target_scope,
        COUNT(*) AS identity_rows,
        COUNT(DISTINCT quantity) AS quantity_variants,
        COUNT(DISTINCT BINARY name) AS name_variants,
        COUNT(DISTINCT COALESCE(current_chapter, -2147483648)) AS chapter_variants
    FROM {$goals}
    GROUP BY user_id, language_norm, type_norm, target_scope
    HAVING COUNT(*) > 1
) duplicate_groups
SQL);

        $counts = $connection->selectOne(<<<SQL
SELECT
    COUNT(*) AS goals_total,
    SUM(CASE WHEN target_id IS NULL THEN 1 ELSE 0 END) AS target_null_rows,
    SUM(CASE WHEN target_id IS NOT NULL AND TRIM(target_id) = '' THEN 1 ELSE 0 END) AS target_empty_rows,
    SUM(CASE WHEN BINARY language <> BINARY LOWER(TRIM(language)) THEN 1 ELSE 0 END) AS language_drift_rows,
    SUM(CASE WHEN BINARY type <> BINARY LOWER(TRIM(type)) THEN 1 ELSE 0 END) AS type_drift_rows,
    SUM(CASE WHEN target_id IS NOT NULL AND BINARY target_id <> BINARY TRIM(target_id) THEN 1 ELSE 0 END) AS target_trim_drift_rows,
    SUM(CASE
        WHEN target_id IS NOT NULL
            AND TRIM(target_id) REGEXP '^[0-9]+$'
            AND TRIM(target_id) <> CASE
                WHEN TRIM(LEADING '0' FROM TRIM(target_id)) = '' THEN '0'
                ELSE TRIM(LEADING '0' FROM TRIM(target_id))
            END
        THEN 1 ELSE 0 END
    ) AS target_numeric_noncanonical_rows,
    SUM(CASE
        WHEN LOWER(TRIM(type)) NOT IN ('review', 'read_words', 'learn_words', 'read_book_chapter')
        THEN 1 ELSE 0 END
    ) AS unsupported_type_rows,
    SUM(CASE
        WHEN LOWER(TRIM(type)) IN ('review', 'read_words', 'learn_words')
            AND target_id IS NOT NULL AND TRIM(target_id) <> ''
        THEN 1 ELSE 0 END
    ) AS default_type_with_target_rows,
    SUM(CASE
        WHEN LOWER(TRIM(type)) = 'read_book_chapter'
            AND (target_id IS NULL OR TRIM(target_id) = '')
        THEN 1 ELSE 0 END
    ) AS targeted_type_without_target_rows,
    SUM(CASE WHEN LOWER(TRIM(type)) = 'review' THEN 1 ELSE 0 END) AS review_rows,
    SUM(CASE WHEN LOWER(TRIM(type)) = 'read_words' THEN 1 ELSE 0 END) AS read_words_rows,
    SUM(CASE WHEN LOWER(TRIM(type)) = 'learn_words' THEN 1 ELSE 0 END) AS learn_words_rows,
    SUM(CASE WHEN LOWER(TRIM(type)) = 'read_book_chapter' THEN 1 ELSE 0 END) AS read_book_chapter_rows
FROM {$goals}
SQL);

        $drift = $connection->selectOne(<<<SQL
SELECT COUNT(*) AS identity_drift_rows
FROM {$goals}
WHERE BINARY language <> BINARY LOWER(TRIM(language))
    OR BINARY type <> BINARY LOWER(TRIM(type))
    OR (target_id IS NOT NULL AND TRIM(target_id) = '')
    OR (target_id IS NOT NULL AND BINARY target_id <> BINARY TRIM(target_id))
    OR (
        target_id IS NOT NULL
        AND TRIM(target_id) REGEXP '^[0-9]+$'
        AND TRIM(target_id) <> CASE
            WHEN TRIM(LEADING '0' FROM TRIM(target_id)) = '' THEN '0'
            ELSE TRIM(LEADING '0' FROM TRIM(target_id))
        END
    )
    OR LOWER(TRIM(type)) NOT IN ('review', 'read_words', 'learn_words', 'read_book_chapter')
    OR (
        LOWER(TRIM(type)) IN ('review', 'read_words', 'learn_words')
        AND target_id IS NOT NULL
        AND TRIM(target_id) <> ''
    )
    OR (
        LOWER(TRIM(type)) = 'read_book_chapter'
        AND (target_id IS NULL OR TRIM(target_id) = '')
    )
SQL);

        $danglingAchievements = 0;
        $achievementTotal = 0;
        if ($achievementsTable !== null) {
            $achievements = self::quotedIdentifier($achievementsTable);
            $achievementTotal = (int) ($connection->selectOne(
                "SELECT COUNT(*) AS aggregate FROM {$achievements}"
            )->aggregate ?? 0);
            $danglingAchievements = (int) ($connection->selectOne(<<<SQL
SELECT COUNT(*) AS aggregate
FROM {$achievements} achievements
LEFT JOIN {$goals} goals ON goals.id = achievements.goal_id
WHERE goals.id IS NULL
SQL)->aggregate ?? 0);
        }

        $result = [
            'goals_total' => (int) ($counts->goals_total ?? 0),
            'goal_achievements_total' => $achievementTotal,
            'duplicate_identity_groups' => (int) ($duplicate->duplicate_identity_groups ?? 0),
            'duplicate_identity_rows' => (int) ($duplicate->duplicate_identity_rows ?? 0),
            'conflict_identity_groups' => (int) ($duplicate->conflict_identity_groups ?? 0),
            'identity_drift_rows' => (int) ($drift->identity_drift_rows ?? 0),
            'language_drift_rows' => (int) ($counts->language_drift_rows ?? 0),
            'type_drift_rows' => (int) ($counts->type_drift_rows ?? 0),
            'target_null_rows' => (int) ($counts->target_null_rows ?? 0),
            'target_empty_rows' => (int) ($counts->target_empty_rows ?? 0),
            'target_trim_drift_rows' => (int) ($counts->target_trim_drift_rows ?? 0),
            'target_numeric_noncanonical_rows' => (int) ($counts->target_numeric_noncanonical_rows ?? 0),
            'unsupported_type_rows' => (int) ($counts->unsupported_type_rows ?? 0),
            'default_type_with_target_rows' => (int) ($counts->default_type_with_target_rows ?? 0),
            'targeted_type_without_target_rows' => (int) ($counts->targeted_type_without_target_rows ?? 0),
            'dangling_goal_achievement_rows' => $danglingAchievements,
            'type_counts' => [
                'review' => (int) ($counts->review_rows ?? 0),
                'read_words' => (int) ($counts->read_words_rows ?? 0),
                'learn_words' => (int) ($counts->learn_words_rows ?? 0),
                'read_book_chapter' => (int) ($counts->read_book_chapter_rows ?? 0),
                'other' => (int) ($counts->unsupported_type_rows ?? 0),
            ],
        ];
        $result['has_issues'] = self::issueCount($result) > 0;

        return $result;
    }

    public static function addConstraint(
        ConnectionInterface $connection,
        string $goalsTable = 'goals',
        ?string $achievementsTable = 'goal_achievements',
    ): void {
        $hasColumn = self::columnExists($connection, $goalsTable, self::FINGERPRINT_COLUMN);
        $hasIndex = self::indexExists($connection, $goalsTable, self::UNIQUE_INDEX);

        if ($hasColumn && $hasIndex) {
            return;
        }

        if ($hasColumn || $hasIndex) {
            throw new RuntimeException('The goal identity schema is partially applied.');
        }

        $audit = self::audit($connection, $goalsTable, $achievementsTable);
        if ($audit['has_issues']) {
            throw new RuntimeException(sprintf(
                'Goal identity constraint blocked: duplicate_groups=%d, drift_rows=%d, dangling_achievements=%d.',
                $audit['duplicate_identity_groups'],
                self::driftCount($audit),
                $audit['dangling_goal_achievement_rows'],
            ));
        }

        $table = self::quotedIdentifier($goalsTable);
        $column = self::quotedIdentifier(self::FINGERPRINT_COLUMN);
        $index = self::quotedIdentifier(self::UNIQUE_INDEX);
        $fingerprint = self::fingerprintSql();

        $connection->statement(<<<SQL
ALTER TABLE {$table}
    ADD COLUMN {$column} BINARY(32)
        GENERATED ALWAYS AS ({$fingerprint}) STORED,
    ADD UNIQUE INDEX {$index} ({$column})
SQL);
    }

    public static function removeConstraint(
        ConnectionInterface $connection,
        string $goalsTable = 'goals',
    ): void {
        $hasColumn = self::columnExists($connection, $goalsTable, self::FINGERPRINT_COLUMN);
        $hasIndex = self::indexExists($connection, $goalsTable, self::UNIQUE_INDEX);
        $table = self::quotedIdentifier($goalsTable);
        $column = self::quotedIdentifier(self::FINGERPRINT_COLUMN);
        $index = self::quotedIdentifier(self::UNIQUE_INDEX);

        if ($hasIndex && $hasColumn) {
            $connection->statement("ALTER TABLE {$table} DROP INDEX {$index}, DROP COLUMN {$column}");

            return;
        }

        if ($hasIndex) {
            $connection->statement("ALTER TABLE {$table} DROP INDEX {$index}");
        }

        if ($hasColumn) {
            $connection->statement("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }

    public static function constraintPresent(
        ConnectionInterface $connection,
        string $goalsTable = 'goals',
    ): bool {
        return self::columnExists($connection, $goalsTable, self::FINGERPRINT_COLUMN)
            && self::indexExists($connection, $goalsTable, self::UNIQUE_INDEX);
    }

    public static function isIdentityDuplicate(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains(
                mb_strtolower($exception->getMessage(), 'UTF-8'),
                mb_strtolower(self::UNIQUE_INDEX, 'UTF-8'),
            );
    }

    private static function issueCount(array $audit): int
    {
        return $audit['duplicate_identity_groups']
            + self::driftCount($audit)
            + $audit['dangling_goal_achievement_rows'];
    }

    private static function driftCount(array $audit): int
    {
        return $audit['identity_drift_rows'];
    }

    private static function fingerprintSql(): string
    {
        $targetScope = self::targetScopeSql('target_id');
        $payload = <<<SQL
CONCAT(
    'U', CHAR_LENGTH(CAST(user_id AS CHAR)), ':', CAST(user_id AS CHAR),
    '|L', CHAR_LENGTH(LOWER(TRIM(language))), ':', LOWER(TRIM(language)),
    '|T', CHAR_LENGTH(LOWER(TRIM(type))), ':', LOWER(TRIM(type)),
    '|S', CHAR_LENGTH({$targetScope}), ':', {$targetScope}
)
SQL;

        return "UNHEX(SHA2({$payload}, 256))";
    }

    private static function targetScopeSql(string $column): string
    {
        $column = self::quotedIdentifier($column);

        return <<<SQL
CASE
    WHEN {$column} IS NULL OR TRIM({$column}) = '' THEN 'd:'
    WHEN TRIM({$column}) REGEXP '^[0-9]+$' THEN CONCAT(
        'n:',
        CASE
            WHEN TRIM(LEADING '0' FROM TRIM({$column})) = '' THEN '0'
            ELSE TRIM(LEADING '0' FROM TRIM({$column}))
        END
    )
    ELSE CONCAT('s:', TRIM({$column}))
END
SQL;
    }

    private static function columnExists(
        ConnectionInterface $connection,
        string $table,
        string $column,
    ): bool {
        $table = self::quotedIdentifier($table);
        $columnLiteral = str_replace("'", "''", $column);

        return $connection->selectOne(
            "SHOW COLUMNS FROM {$table} WHERE Field = '{$columnLiteral}'"
        ) !== null;
    }

    private static function indexExists(
        ConnectionInterface $connection,
        string $table,
        string $index,
    ): bool {
        $table = self::quotedIdentifier($table);
        $indexLiteral = str_replace("'", "''", $index);

        return $connection->selectOne(
            "SHOW INDEX FROM {$table} WHERE Key_name = '{$indexLiteral}'"
        ) !== null;
    }

    private static function quotedIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('Unsafe database identifier.');
        }

        return '`'.$identifier.'`';
    }
}
