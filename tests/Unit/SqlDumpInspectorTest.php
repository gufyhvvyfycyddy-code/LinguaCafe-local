<?php

namespace Tests\Unit;

use App\Exceptions\BackupException;
use App\Services\SqlDumpInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SqlDumpInspectorTest extends TestCase
{
    public function test_inspects_gzip_dump_and_accepts_known_mysqldump_session_directives(): void
    {
        $path = $this->gzip(<<<'SQL'
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
/*!40101 SET character_set_client = @saved_cs_client */;
CREATE TABLE `migrations` (`id` bigint);
CREATE TABLE `users` (`id` bigint);
SQL);

        try {
            $result = app(SqlDumpInspector::class)->inspect($path, ['migrations', 'users']);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['migrations', 'users'], $result['tables']);
        $this->assertGreaterThan(0, $result['uncompressed_size_bytes']);
        $this->assertSame(64, strlen($result['schema_fingerprint']));
    }

    #[DataProvider('unsafeDumpProvider')]
    public function test_rejects_unsafe_dump_statements(string $statement): void
    {
        $path = $this->gzip("CREATE TABLE `users` (`id` bigint);\n{$statement}\n");

        try {
            app(SqlDumpInspector::class)->inspect($path, ['users']);
            $this->fail('Expected unsafe statement rejection.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_STATEMENT_UNSUPPORTED', $exception->errorCode);
        } finally {
            @unlink($path);
        }
    }

    public static function unsafeDumpProvider(): array
    {
        return [
            'database selection' => ['USE `other`;'],
            'filesystem export' => ['SELECT * FROM users INTO OUTFILE "/tmp/users";'],
            'cross database table' => ['INSERT INTO `other`.`users` VALUES (1);'],
            'unapproved executable comment' => ['/*!50003 CREATE*/'],
            'global setting' => ['SET GLOBAL general_log = 1;'],
            'persistent setting in executable comment' => [
                "/*!80000 SET PERSIST SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;",
            ],
            'extra executable comment assignment' => [
                "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO', SQL_NOTES=0 */;",
            ],
            'extra charset executable assignment' => [
                "/*!40101 SET character_set_client = utf8mb4, SQL_NOTES=0 */;",
            ],
            'cross database foreign key' => [
                'CREATE TABLE `unsafe_fk` (`id` bigint, CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `other`.`users` (`id`));',
            ],
            'data directory table option' => [
                "CREATE TABLE `unsafe_data` (`id` bigint) DATA DIRECTORY='/tmp';",
            ],
            'index directory table option' => [
                "CREATE TABLE `unsafe_index` (`id` bigint) INDEX DIRECTORY='/tmp';",
            ],
            'tablespace table option' => [
                'CREATE TABLE `unsafe_tablespace` (`id` bigint) TABLESPACE external_space;',
            ],
            'federated connection table option' => [
                "CREATE TABLE `unsafe_connection` (`id` bigint) ENGINE=FEDERATED CONNECTION='mysql://remote';",
            ],
            'create table select' => [
                'CREATE TABLE `unsafe_select` (`id` bigint) SELECT `id` FROM `users`;',
            ],
            'insert duplicate key tail' => [
                'INSERT INTO `users` VALUES (1) ON DUPLICATE KEY UPDATE `id` = SLEEP(1);',
            ],
            'insert subquery value' => [
                'INSERT INTO `users` VALUES ((SELECT `id` FROM `other`.`users` LIMIT 1));',
            ],
            'delimiter confusion' => ['DELIMITER ;;'],
        ];
    }

    public function test_create_table_danger_words_inside_literals_or_identifiers_are_not_executed(): void
    {
        $path = $this->gzip(
            "CREATE TABLE `users` (`connection` varchar(20) DEFAULT 'tablespace');",
        );

        try {
            $result = app(SqlDumpInspector::class)->inspect($path, ['users']);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['users'], $result['tables']);
    }

    public function test_accepts_literal_only_multirow_mysqldump_insert(): void
    {
        $path = $this->gzip(<<<'SQL'
CREATE TABLE `users` (`id` bigint, `name` varchar(20), `blob` blob);
INSERT INTO `users` VALUES (1,'Alice',0xCAFE),(2,NULL,0x00);
SQL);

        try {
            $result = app(SqlDumpInspector::class)->inspect($path, ['users']);
        } finally {
            @unlink($path);
        }

        $this->assertSame(['users'], $result['tables']);
    }

    public function test_rejects_missing_required_table(): void
    {
        $path = $this->gzip('CREATE TABLE `users` (`id` bigint);');

        try {
            app(SqlDumpInspector::class)->inspect($path, ['users', 'migrations']);
            $this->fail('Expected missing table rejection.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_REQUIRED_TABLE_MISSING', $exception->errorCode);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_forbidden_statement_after_allowed_statement_on_same_line(): void
    {
        $path = $this->gzip(
            'CREATE TABLE `users` (`id` bigint); DROP DATABASE `active`;',
        );

        try {
            app(SqlDumpInspector::class)->inspect($path, ['users']);
            $this->fail('Expected second statement rejection.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_STATEMENT_UNSUPPORTED', $exception->errorCode);
        } finally {
            @unlink($path);
        }
    }

    public function test_rejects_multiline_and_comment_prefixed_bypass_attempts(): void
    {
        foreach ([
            "CREATE TABLE `users` (`id` bigint);\nSET\nGLOBAL general_log=1;",
            "CREATE TABLE `users` (`id` bigint); /* harmless */ DROP USER `app`@`%`;",
        ] as $dump) {
            $path = $this->gzip($dump);
            try {
                app(SqlDumpInspector::class)->inspect($path, ['users']);
                $this->fail('Expected lexer-level statement rejection.');
            } catch (BackupException $exception) {
                $this->assertSame('BACKUP_RESTORE_STATEMENT_UNSUPPORTED', $exception->errorCode);
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_rejects_expansion_beyond_configured_limit(): void
    {
        config(['backup.restore_max_uncompressed_bytes' => 10]);
        $path = $this->gzip('CREATE TABLE `users` (`id` bigint);');

        try {
            app(SqlDumpInspector::class)->inspect($path, ['users']);
            $this->fail('Expected expansion limit rejection.');
        } catch (BackupException $exception) {
            $this->assertSame('BACKUP_RESTORE_ARCHIVE_TOO_LARGE', $exception->errorCode);
        } finally {
            @unlink($path);
        }
    }

    private function gzip(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lc-inspect-') . '.sql.gz';
        file_put_contents($path, gzencode($contents, 9));

        return $path;
    }
}
