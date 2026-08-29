<?php

namespace Tests\Unit;

use Tests\TestCase;

class H04BackupRestoreDrillContractTest extends TestCase
{
    public function test_production_image_uses_oracle_mysql_client_for_mysql_server_runtime(): void
    {
        $dockerfile = file_get_contents(base_path('docker/PhpDockerfile'));

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('FROM php:8.4-apache-trixie', $dockerfile);
        $this->assertStringContainsString('repo.mysql.com/apt/debian/ trixie mysql-8.4-lts', $dockerfile);
        $this->assertStringContainsString('mysql-community-client', $dockerfile);
        $this->assertStringNotContainsString('default-mysql-client', $dockerfile);
        $this->assertStringNotContainsString('mariadb-client', $dockerfile);
    }

    public function test_h04_runtime_is_disposable_and_has_only_the_required_services(): void
    {
        $compose = file_get_contents(base_path('docker-compose.h04-testing.yml'));

        $this->assertIsString($compose);
        $this->assertSame(1, preg_match('/^\s{4}mysql:\s*$/m', $compose));
        $this->assertSame(1, preg_match('/^\s{4}redis:\s*$/m', $compose));
        $this->assertSame(1, preg_match('/^\s{4}web:\s*$/m', $compose));
        $this->assertStringContainsString('mysql:8.4', $compose);
        $this->assertStringContainsString('redis:7.2-alpine', $compose);
        $this->assertStringContainsString('/var/lib/mysql', $compose);
        $this->assertStringContainsString('127.0.0.1:8894:80', $compose);
        $this->assertStringNotContainsString('3306:3306', $compose);
        $this->assertStringNotContainsString('env_file:', $compose);
    }

    public function test_h04_drill_is_fail_closed_and_covers_success_and_rollback_without_destructive_reset_commands(): void
    {
        $drill = file_get_contents(base_path('tests/Support/run-h04-backup-restore-drill.php'));
        $runtime = file_get_contents(base_path('tests/Support/run-h04-container-runtime.php'));
        $combined = (string) $drill.(string) $runtime;

        $this->assertStringContainsString('H04_DRILL_MYSQL_CLIENT_INCOMPATIBLE', (string) $drill);
        $this->assertStringContainsString("'--rollback'", (string) $drill);
        $this->assertStringContainsString("'--verify-rollback'", (string) $drill);
        $this->assertStringContainsString('H04_CONTAINER_TAMPER_FAILED', (string) $runtime);
        $this->assertStringContainsString('BACKUP_RESTORE_WRITE_FENCE_ACTIVE', $combined);
        $this->assertStringNotContainsString('migrate:fresh', $combined);
        $this->assertStringNotContainsString('migrate:refresh', $combined);
        $this->assertStringNotContainsString('db:wipe', $combined);
        $this->assertStringNotContainsString('docker system prune', $combined);
    }
}
