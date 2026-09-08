<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #54 regression guard. A clean MySQL build (utf8mb4) previously failed because
 * composite unique indexes spanned default VARCHAR(255) columns whose combined
 * key length exceeded InnoDB's 3072-byte limit. These assertions lock in the
 * canonical bounded widths so the fresh-schema drift cannot silently return.
 *
 * Uses RefreshDatabase so the assertions run against the actually-built schema.
 */
class FreshSchemaIndexLengthTest extends TestCase
{
    use RefreshDatabase;

    private const INNODB_MAX_KEY_BYTES = 3072;

    public function test_ai_pending_index_columns_are_bounded(): void
    {
        $this->assertColumnMaxLength('ai_study_card_pending_items', 'language_id', 64);
        $this->assertColumnMaxLength('ai_study_card_pending_items', 'status', 32);
        $this->assertUniqueIndexFitsInnodbLimit(
            'ai_study_card_pending_items',
            'ai_pending_user_lang_chapter_block_word_status_unique',
        );
    }

    public function test_reading_inline_sense_confirmation_index_columns_are_bounded(): void
    {
        $this->assertColumnMaxLength('reading_inline_sense_confirmations', 'language', 64);
        $this->assertUniqueIndexFitsInnodbLimit(
            'reading_inline_sense_confirmations',
            'risc_user_lang_chapter_sentence_surface_lemma_sense_unique',
        );
    }

    private function assertColumnMaxLength(string $table, string $column, int $expected): void
    {
        $len = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('CHARACTER_MAXIMUM_LENGTH');

        $this->assertNotNull($len, "{$table}.{$column} should exist as a string column");
        $this->assertSame($expected, (int) $len, "{$table}.{$column} must be bounded to {$expected} chars");
    }

    private function assertUniqueIndexFitsInnodbLimit(string $table, string $indexName): void
    {
        // Sum the byte cost of each indexed column: character columns cost
        // CHARACTER_OCTET_LENGTH (already charset-aware), others are small.
        $columns = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->pluck('COLUMN_NAME');

        $this->assertNotEmpty($columns, "unique index {$indexName} must exist on {$table}");

        $bytes = 0;
        foreach ($columns as $col) {
            $meta = DB::table('information_schema.columns')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('column_name', $col)
                ->first(['CHARACTER_OCTET_LENGTH', 'DATA_TYPE']);

            $bytes += (int) ($meta->CHARACTER_OCTET_LENGTH ?? 8);
        }

        $this->assertLessThanOrEqual(
            self::INNODB_MAX_KEY_BYTES,
            $bytes,
            "unique index {$indexName} key length ({$bytes} bytes) must not exceed InnoDB's limit",
        );
    }
}
