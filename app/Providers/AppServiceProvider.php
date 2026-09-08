<?php

namespace App\Providers;

use App\Services\AiStudyCardV6DisabledProviderAdapter;
use App\Services\AiStudyCardV6OpenAiCompatibleHttpTransport;
use App\Services\AiStudyCardV6OpenAiCompatibleProviderAdapter;
use App\Services\AiStudyCardV6ProviderInterface;
use App\Services\AiStudyCardV6ProviderTransportInterface;
use App\Services\CustomStudy\ChapterLocatorInterface;
use App\Services\CustomStudy\EloquentChapterLocator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // V6 provider boundary: disabled by default. A real adapter is bound
        // only when the user manually configures the local environment.
        $this->app->bind(AiStudyCardV6ProviderTransportInterface::class, AiStudyCardV6OpenAiCompatibleHttpTransport::class);

        $this->app->bind(AiStudyCardV6ProviderInterface::class, function ($app) {
            if (
                config('ai_study_card_v6.provider.external_requests_enabled')
                && config('ai_study_card_v6.provider.allowed_adapter') === 'openai_compatible'
            ) {
                return $app->make(AiStudyCardV6OpenAiCompatibleProviderAdapter::class);
            }

            return $app->make(AiStudyCardV6DisabledProviderAdapter::class);
        });

        // Custom Study Phase 2B (Task 2000-18): production binding for
        // ChapterLocatorInterface. EloquentChapterLocator queries only the
        // chapters table (user_id + language) via exists().
        $this->app->bind(ChapterLocatorInterface::class, EloquentChapterLocator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $guardedConnections = [];
        $installRestoreWriteGuard = function (Connection $connection) use (&$guardedConnections): void {
            $connectionId = spl_object_id($connection);
            if (isset($guardedConnections[$connectionId])) {
                return;
            }

            $guardedConnections[$connectionId] = true;
            $connection->beforeExecuting(function (string $query): void {
                $this->app->make(\App\Services\RestoreWriteFence::class)
                    ->assertQueryAllowed($query);
            });
        };

        Event::listen(
            ConnectionEstablished::class,
            function (ConnectionEstablished $event) use ($installRestoreWriteGuard): void {
                $installRestoreWriteGuard($event->connection);
            },
        );
        foreach ($this->app->make('db')->getConnections() as $connection) {
            $installRestoreWriteGuard($connection);
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if ($this->app->make(\App\Services\RestoreWriteFence::class)->active()) {
                throw new RuntimeException(
                    'Console writes are fenced while database recovery is running.',
                );
            }
        });

        // Source #55 (stage A): fail-closed containment. Refuse destructive
        // schema-reset commands (e.g. RefreshDatabase's migrate:fresh) unless
        // they target a unique per-run, process-owned disposable database, so a
        // test run can never reset a shared/long-lived testing database.
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! \App\Services\DisposableTestDatabaseGuard::isGuardedCommand($event->command)) {
                return;
            }

            $connectionName = null;
            $input = $event->input;
            if ($input !== null) {
                try {
                    if ($input->hasOption('database')) {
                        $option = $input->getOption('database');
                        if (is_string($option) && $option !== '') {
                            $connectionName = $option;
                        }
                    }
                } catch (\Throwable) {
                    // Option not bound yet; fall back to the default connection.
                }
            }

            $this->app->make(\App\Services\DisposableTestDatabaseGuard::class)
                ->assertDestructiveResetAllowed($event->command, $connectionName);
        });

        // Source #55 (stage A): reliable connection-layer backstop. Console
        // events are not dispatched for programmatic Kernel::call (e.g.
        // RefreshDatabase's migrate:fresh), so also refuse destructive schema
        // statements at execution time — before the first drop runs — unless the
        // connection's database is a sanctioned disposable run database.
        if ($this->app->environment('testing')) {
            $installDisposableDbGuard = function (Connection $connection) use (&$guardedConnections): void {
                $guardKey = 'testdb:' . spl_object_id($connection);
                if (isset($guardedConnections[$guardKey])) {
                    return;
                }
                $guardedConnections[$guardKey] = true;
                $connection->beforeExecuting(function (string $query) use ($connection): void {
                    $this->app->make(\App\Services\DisposableTestDatabaseGuard::class)
                        ->assertQueryAllowed($query, (string) $connection->getDatabaseName());
                });
            };

            Event::listen(
                ConnectionEstablished::class,
                function (ConnectionEstablished $event) use ($installDisposableDbGuard): void {
                    $installDisposableDbGuard($event->connection);
                },
            );
            foreach ($this->app->make('db')->getConnections() as $connection) {
                $installDisposableDbGuard($connection);
            }
        }
    }
}
