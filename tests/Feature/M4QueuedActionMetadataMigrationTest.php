<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class M4QueuedActionMetadataMigrationTest extends TestCase
{
    public function test_migration_rolls_back_and_restores_on_empty_testing_schema(): void
    {
        $this->assertSame(0, DB::table('operations')->count());
        $migration = require database_path(
            'migrations/2026_07_29_000002_add_queued_action_metadata_to_operations.php',
        );

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('operations', 'client_occurred_at'));
            $this->assertFalse(Schema::hasColumn('operations', 'client_sequence'));
            $this->assertFalse(Schema::hasColumn('operations', 'batch_id'));
        } finally {
            if (!Schema::hasColumn('operations', 'client_occurred_at')) {
                $migration->up();
            }
        }

        $this->assertTrue(Schema::hasColumn('operations', 'client_occurred_at'));
        $this->assertTrue(Schema::hasColumn('operations', 'client_sequence'));
        $this->assertTrue(Schema::hasColumn('operations', 'batch_id'));
    }
}
