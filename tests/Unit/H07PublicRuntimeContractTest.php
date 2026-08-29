<?php

namespace Tests\Unit;

use Tests\TestCase;

class H07PublicRuntimeContractTest extends TestCase
{
    public function test_supported_framework_and_php_runtime_are_declared(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('^8.4', $composer['require']['php']);
        $this->assertSame('^13.0', $composer['require']['laravel/framework']);
        $this->assertSame('^12.0', $composer['require-dev']['phpunit/phpunit']);

        $productionDockerfile = file_get_contents(base_path('docker/PhpDockerfile'));
        $developmentDockerfile = file_get_contents(base_path('docker/PhpDockerfileDev'));

        $this->assertStringContainsString('FROM php:8.4-apache-trixie', $productionDockerfile);
        $this->assertStringContainsString('FROM php:8.4-apache', $developmentDockerfile);
        $this->assertStringNotContainsString('docker-php-ext-install pdo pdo_mysql fileinfo', $productionDockerfile);
        $this->assertStringNotContainsString('docker-php-ext-install pdo pdo_mysql fileinfo', $developmentDockerfile);
    }

    public function test_tokenizer_images_include_only_the_supported_english_spacy_runtime(): void
    {
        foreach (['docker/PythonDockerfile', 'docker/PythonDockerfileDev'] as $path) {
            $dockerfile = file_get_contents(base_path($path));

            $this->assertStringContainsString('lemminflect', $dockerfile, $path);
            $this->assertStringContainsString("'spacy>=3.8,<3.9'", $dockerfile, $path);
            $this->assertStringContainsString('en_core_web_sm-3.8.0-py3-none-any.whl', $dockerfile, $path);
            $this->assertStringNotContainsString('de_core_news_sm', $dockerfile, $path);
            $this->assertStringNotContainsString('fr_core_news_sm', $dockerfile, $path);
            $this->assertStringNotContainsString('xx_ent_wiki_sm', $dockerfile, $path);
            $this->assertStringNotContainsString('CMD [ "export PYTHONPATH=', $dockerfile, $path);
        }
    }

    public function test_default_compose_builds_the_current_checkout_and_keeps_internal_services_private(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('dockerfile: ./docker/PhpDockerfile', $compose);
        $this->assertStringContainsString('dockerfile: ./docker/PythonDockerfile', $compose);
        $this->assertStringContainsString('image: mysql:8.4', $compose);
        $this->assertStringNotContainsString('ghcr.io/simjanos-dev/linguacafe-webserver', $compose);
        $this->assertStringNotContainsString('ghcr.io/simjanos-dev/linguacafe-python-service', $compose);
        $this->assertStringContainsString('REDIS_HOST: linguacafe-redis', $compose);
        $this->assertStringContainsString('PYTHON_CONTAINER_NAME: linguacafe-python-service', $compose);
        $this->assertStringContainsString('TRUSTED_PROXIES:', $compose);
        $this->assertStringNotContainsString('6379:6379', $compose);
        $this->assertStringNotContainsString('--general-log=1', $compose);
        $this->assertStringContainsString('$$MYSQL_ROOT_PASSWORD', $compose);
    }

    public function test_current_compose_mysql_runtimes_use_8_4_lts_and_container_scoped_healthcheck_passwords(): void
    {
        foreach ([
            'docker-compose.yml',
            'docker-compose-dev.yml',
            'docker-compose-dev-macos.yml',
            'docker-compose.h02-testing.yml',
            'docker-compose.h04-testing.yml',
        ] as $path) {
            $compose = file_get_contents(base_path($path));

            $this->assertStringContainsString('image: mysql:8.4', $compose, $path);
            $this->assertStringContainsString('$$MYSQL_ROOT_PASSWORD', $compose, $path);
        }
    }

    public function test_public_compose_fails_closed_when_required_secrets_are_missing(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('APP_KEY: ${APP_KEY:?', $compose);
        $this->assertStringContainsString('APP_URL: ${APP_URL:?', $compose);
        $this->assertStringContainsString('DB_PASSWORD: ${DB_PASSWORD:?', $compose);
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD:?', $compose);
    }

    public function test_container_startup_does_not_implicitly_migrate_or_seed_the_production_database(): void
    {
        $productionEntrypoint = file_get_contents(base_path('entrypoint.sh'));
        $developmentEntrypoint = file_get_contents(base_path('entrypoint-dev.sh'));

        $this->assertStringNotContainsString('artisan migrate', $productionEntrypoint);
        $this->assertStringNotContainsString('artisan db:seed', $productionEntrypoint);
        $this->assertStringNotContainsString('--force', $productionEntrypoint);
        $this->assertStringNotContainsString('--force', $developmentEntrypoint);
    }

    public function test_trusted_proxy_configuration_uses_one_explicit_deployment_setting(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("env('TRUSTED_PROXIES')", $bootstrap);
        $this->assertStringContainsString('$middleware->trustProxies(at: $trustedProxies);', $bootstrap);
        $this->assertStringNotContainsString("trustProxies(at: '*')", $bootstrap);
    }
}
