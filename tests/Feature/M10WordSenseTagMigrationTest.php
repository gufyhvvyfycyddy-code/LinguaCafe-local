<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class M10WordSenseTagMigrationTest extends TestCase
{
    public function test_migration_rolls_back_and_restores_on_empty_testing_schema(): void
    {
        $this->assertSame(0, DB::table('word_sense_tag_assignments')->count());
        $this->assertSame(0, DB::table('word_sense_tags')->count());

        $migration = require database_path(
            'migrations/2026_07_28_000003_create_word_sense_tags_tables.php'
        );

        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('word_sense_tag_assignments'));
            $this->assertFalse(Schema::hasTable('word_sense_tags'));
        } finally {
            if (!Schema::hasTable('word_sense_tags')) {
                $migration->up();
            }
        }

        $this->assertTrue(Schema::hasTable('word_sense_tags'));
        $this->assertTrue(Schema::hasTable('word_sense_tag_assignments'));
    }
}
