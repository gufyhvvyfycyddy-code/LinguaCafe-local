<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class H04RestoreRequiredTablesContractTest extends TestCase
{
    public function test_every_configured_restore_required_table_exists_in_the_current_schema(): void
    {
        $requiredTables = config('backup.restore_required_tables');

        $this->assertIsArray($requiredTables);
        $this->assertNotEmpty($requiredTables);

        foreach ($requiredTables as $table) {
            $this->assertIsString($table);
            $this->assertTrue(
                Schema::hasTable($table),
                "Configured restore required table [{$table}] does not exist in the current schema.",
            );
        }
    }
}
