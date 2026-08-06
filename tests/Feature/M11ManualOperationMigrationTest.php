<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class M11ManualOperationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_rolls_back_and_restores_on_empty_testing_schema(): void
    {
        $migration = require database_path(
            'migrations/2026_07_28_000004_add_manual_operation_metadata.php',
        );

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn('operations', 'source_channel'));
            $this->assertFalse(Schema::hasColumn('operations', 'action_payload'));
            $this->assertFalse(Schema::hasColumn('operations', 'request_fingerprint'));
            $this->assertFalse(Schema::hasColumn(
                'operation_changes',
                'actor_source_channel',
            ));
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn('operations', 'source_channel'));
        $this->assertTrue(Schema::hasColumn('operations', 'action_payload'));
        $this->assertTrue(Schema::hasColumn('operations', 'request_fingerprint'));
        $this->assertTrue(Schema::hasColumn(
            'operation_changes',
            'actor_source_channel',
        ));
    }
}
