<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class M12SpecialStudyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_rolls_back_and_restores_on_empty_testing_schema(): void
    {
        $migration = require database_path(
            'migrations/2026_07_29_000001_create_special_study_sessions.php',
        );

        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('special_study_session_actions'));
            $this->assertFalse(Schema::hasTable('special_study_sessions'));
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('special_study_sessions'));
        $this->assertTrue(Schema::hasTable('special_study_session_actions'));
        $this->assertTrue(Schema::hasColumns('special_study_sessions', [
            'definition',
            'ordered_card_ids',
            'remaining_card_ids',
            'revision',
            'status',
        ]));
        $this->assertTrue(Schema::hasColumns('special_study_session_actions', [
            'client_action_id',
            'request_hash',
            'operation_id',
            'response_body',
        ]));
    }
}
