<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class H02RepresentativeRuntimeContractTest extends TestCase
{
    private function compose(): string
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.h02-testing.yml');
        $this->assertIsString($compose, 'H-02 compose contract must be readable.');

        return $compose;
    }

    private function serviceBlock(string $compose, string $service): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $compose);
        $header = "    {$service}:";
        $start = null;

        foreach ($lines as $index => $line) {
            if ($line === $header) {
                $start = $index;
                break;
            }
        }

        $this->assertNotNull($start, "H-02 compose must define the {$service} service.");

        $block = [$lines[$start]];
        for ($index = $start + 1; $index < count($lines); $index++) {
            if (preg_match('/^    [A-Za-z0-9][A-Za-z0-9_.-]*:$/', $lines[$index]) === 1) {
                break;
            }
            $block[] = $lines[$index];
        }

        return implode("\n", $block);
    }

    public function test_compose_has_only_mysql_and_web_services(): void
    {
        $compose = $this->compose();
        $servicesMatch = [];
        $this->assertSame(
            1,
            preg_match('/^services:\r?\n/m', $compose, $servicesMatch, PREG_OFFSET_CAPTURE),
            'H-02 compose must define a top-level services section.'
        );

        $services = substr($compose, $servicesMatch[0][1] + strlen($servicesMatch[0][0]));
        $serviceNames = [];
        foreach (preg_split('/\r\n|\r|\n/', $services) as $line) {
            $headerMatch = [];
            if (preg_match('/^    ([A-Za-z0-9][A-Za-z0-9_.-]*):$/', $line, $headerMatch) === 1) {
                $serviceNames[] = $headerMatch[1];
            }
        }

        $this->assertSame(
            ['mysql', 'web'],
            array_values($serviceNames),
            'H-02 runtime must expose only mysql and web services in source order.'
        );
    }

    public function test_web_builds_current_checkout_with_existing_php_dockerfile(): void
    {
        $web = $this->serviceBlock($this->compose(), 'web');

        $this->assertStringContainsString('context: .', $web, 'H-02 web must build from the current checkout.');
        $this->assertStringContainsString(
            'dockerfile: docker/PhpDockerfile',
            $web,
            'H-02 web must use the existing PHP Dockerfile.'
        );
        $this->assertStringNotContainsString(
            'image:',
            $web,
            'H-02 web must not override the checkout build with an image line.'
        );
    }

    public function test_clean_container_build_pins_laravel_mix_compatible_webpack(): void
    {
        $package = file_get_contents(dirname(__DIR__, 2).'/package.json');
        $this->assertIsString($package, 'Root package.json must be readable for the H-02 container build.');
        $this->assertStringContainsString(
            '"webpack": "~5.99.9"',
            $package,
            'H-02 clean builds must not drift to webpack releases that removed Laravel Mix 6 internals.'
        );
    }

    public function test_node_build_caches_dependencies_and_excludes_host_dependency_links(): void
    {
        $root = dirname(__DIR__, 2);
        $dockerfile = file_get_contents($root.'/docker/PhpDockerfile');
        $dockerignore = file_get_contents($root.'/.dockerignore');
        $this->assertIsString($dockerfile);
        $this->assertIsString($dockerignore);

        $packageCopy = strpos($dockerfile, 'COPY package.json /build/package.json');
        $npmInstall = strpos($dockerfile, 'RUN npm install');
        $sourceCopy = strpos($dockerfile, 'COPY ./ /build');
        $npmBuild = strpos($dockerfile, 'RUN npm run prod');
        $this->assertIsInt($packageCopy);
        $this->assertIsInt($npmInstall);
        $this->assertIsInt($sourceCopy);
        $this->assertIsInt($npmBuild);
        $this->assertLessThan($npmInstall, $packageCopy);
        $this->assertLessThan($sourceCopy, $npmInstall);
        $this->assertLessThan($npmBuild, $sourceCopy);

        foreach ([".git\n", ".env\n", ".env.*\n", "node_modules\n", "vendor\n", "storage/logs\n"] as $ignored) {
            $this->assertStringContainsString($ignored, $dockerignore);
        }
    }

    public function test_clean_container_build_does_not_require_runtime_broadcast_secrets(): void
    {
        $root = dirname(__DIR__, 2);
        $broadcasting = file_get_contents($root.'/config/broadcasting.php');
        $dockerignore = file_get_contents($root.'/.dockerignore');
        $this->assertIsString($broadcasting);
        $this->assertIsString($dockerignore);

        $this->assertStringContainsString(
            "'default' => env('BROADCAST_CONNECTION', env('BROADCAST_DRIVER', 'null'))",
            $broadcasting,
            'A clean build must use a secret-free null broadcaster unless runtime explicitly configures broadcasting.'
        );
        $this->assertStringNotContainsString("env('BROADCAST_DRIVER', 'pusher')", $broadcasting);
        $this->assertStringContainsString(".env\n", $dockerignore);
        $this->assertStringContainsString(".env.*\n", $dockerignore);
    }

    public function test_mysql_composite_index_columns_are_bounded_for_fresh_install(): void
    {
        $root = dirname(__DIR__, 2);
        $pending = file_get_contents($root.'/database/migrations/2026_07_02_000001_create_ai_study_card_pending_items_table.php');
        $inline = file_get_contents($root.'/database/migrations/2026_07_03_000001_create_reading_inline_sense_confirmations_table.php');

        $this->assertIsString($pending);
        $this->assertIsString($inline);
        $this->assertStringContainsString("string('language_id', 64)", $pending);
        $this->assertStringContainsString("string('status', 32)", $pending);
        $this->assertStringContainsString("string('language', 64)", $inline);
    }

    public function test_web_clears_repository_entrypoint_and_runs_apache_only(): void
    {
        $compose = $this->compose();
        $web = $this->serviceBlock($compose, 'web');

        $this->assertStringContainsString('entrypoint: []', $web, 'H-02 web must clear the repository entrypoint.');
        $this->assertStringContainsString(
            'command: ["apache2-foreground"]',
            $web,
            'H-02 web must run Apache in the foreground as its only command.'
        );

        foreach (['supervisord', 'horizon', 'reverb', 'backup.sh', 'artisan schedule'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                strtolower($compose),
                "H-02 compose must not include the {$forbidden} runtime token."
            );
        }
    }

    public function test_web_uses_testing_runtime_and_disposable_mysql_credentials(): void
    {
        $compose = $this->compose();
        $web = $this->serviceBlock($compose, 'web');
        $mysql = $this->serviceBlock($compose, 'mysql');
        $database = 'linguacafe_h02_testing';
        $username = 'linguacafe_h02';
        $password = 'h02-testing-only';

        foreach ([
            'APP_ENV: testing',
            'APP_DEBUG: "false"',
            'DB_HOST: mysql',
            "DB_DATABASE: {$database}",
            "DB_USERNAME: {$username}",
            "DB_PASSWORD: {$password}",
            'SESSION_DRIVER: file',
            'CACHE_DRIVER: array',
            'QUEUE_CONNECTION: sync',
        ] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $web,
                "H-02 web testing runtime must contain {$expected}."
            );
        }

        foreach ([
            "MYSQL_DATABASE: {$database}",
            "MYSQL_USER: {$username}",
            "MYSQL_PASSWORD: {$password}",
        ] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $mysql,
                "H-02 MySQL credentials must mirror the web DB contract: {$expected}."
            );
        }
    }

    public function test_web_is_localhost_only_and_mysql_is_not_published(): void
    {
        $compose = $this->compose();
        $web = $this->serviceBlock($compose, 'web');
        $mysql = $this->serviceBlock($compose, 'mysql');

        $this->assertSame(
            1,
            substr_count($web, '"127.0.0.1:8892:80"'),
            'H-02 web must expose exactly the localhost-only 8892-to-80 port mapping.'
        );
        $this->assertStringNotContainsString(
            '0.0.0.0',
            $web,
            'H-02 web must not bind its published port to all interfaces.'
        );
        $this->assertStringNotContainsString(
            'ports:',
            $mysql,
            'H-02 MySQL must remain unpublished to the host.'
        );
    }

    public function test_mysql_is_disposable_and_health_gates_web(): void
    {
        $compose = $this->compose();
        $mysql = $this->serviceBlock($compose, 'mysql');
        $web = $this->serviceBlock($compose, 'web');

        $this->assertStringContainsString('tmpfs:', $mysql, 'H-02 MySQL must use disposable tmpfs storage.');
        $this->assertStringContainsString('/var/lib/mysql', $mysql, 'H-02 MySQL tmpfs must cover its data directory.');
        $this->assertStringContainsString('healthcheck:', $mysql, 'H-02 MySQL must define a readiness healthcheck.');
        $this->assertStringContainsString('mysqladmin ping', $mysql, 'H-02 MySQL health must use mysqladmin ping.');
        $this->assertStringContainsString('depends_on:', $web, 'H-02 web must declare a MySQL dependency gate.');
        $this->assertStringContainsString(
            "            mysql:\n                condition: service_healthy",
            $web,
            'H-02 web must wait for the MySQL service_healthy condition.'
        );
    }

    public function test_compose_has_no_host_mounts_env_file_or_interpolation(): void
    {
        $compose = $this->compose();
        $mysql = $this->serviceBlock($compose, 'mysql');
        $web = $this->serviceBlock($compose, 'web');

        foreach (['volumes:', 'env_file:', '${'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $compose,
                "H-02 compose must not contain {$forbidden}."
            );
        }

        $this->assertSame(
            1,
            preg_match_all('/^    h02-testing:$/m', $compose),
            'H-02 compose must declare exactly one h02-testing network.'
        );
        $networkAttachment = "        networks:\n            - h02-testing";
        $this->assertStringContainsString(
            $networkAttachment,
            $mysql,
            'H-02 MySQL must attach to the h02-testing network.'
        );
        $this->assertStringContainsString(
            $networkAttachment,
            $web,
            'H-02 web must attach to the h02-testing network.'
        );
    }

    public function test_compose_contains_no_extra_runtime_services(): void
    {
        $compose = $this->compose();

        foreach (['redis', 'python', 'horizon', 'reverb', 'backup', 'worker', 'scheduler'] as $service) {
            $this->assertSame(
                0,
                preg_match('/^    '.preg_quote($service, '/').':$/m', $compose),
                "H-02 compose must not define a {$service} runtime service."
            );
        }

        $servicesOffset = strpos($compose, "services:\n");
        $this->assertNotFalse($servicesOffset, 'H-02 compose must define a services section.');
        $services = substr($compose, $servicesOffset + strlen("services:\n"));
        preg_match_all('/^    ([A-Za-z0-9][A-Za-z0-9_.-]*):$/m', $services, $matches);
        $this->assertSame(
            ['mysql', 'web'],
            array_values($matches[1]),
            'H-02 compose must keep mysql/web as the only runtime service list.'
        );
    }
}
