<?php

namespace Tests\Feature;

use App\Services\DictionaryImportService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * EP-10: DictionaryImportProgressedEvent is a ShouldBroadcastNow event dispatched
 * synchronously inside the dictionary import HTTP request. An unconfigured or
 * unreachable optional broadcast backend (e.g. Pusher) must not abort the import.
 * These tests pin the fail-soft guard and prove it is load-bearing.
 */
class DictionaryImportBroadcastFailSoftTest extends TestCase
{
    private function useThrowingBroadcaster(string $name): void
    {
        Broadcast::extend($name, function () {
            return new class extends \Illuminate\Broadcasting\Broadcasters\Broadcaster {
                public function auth($request) {}

                public function validAuthenticationResponse($request, $result) {}

                public function broadcast(array $channels, $event, array $payload = [])
                {
                    throw new \RuntimeException('broadcast backend down');
                }
            };
        });
        config(['broadcasting.default' => $name]);
    }

    public function test_import_progress_broadcast_failure_does_not_propagate(): void
    {
        $this->useThrowingBroadcaster('throwing_guarded');
        Log::spy();

        $service = new DictionaryImportService();
        $method = new \ReflectionMethod($service, 'broadcastImportProgress');
        $method->setAccessible(true);

        // Must return normally even though the broadcast backend throws.
        $method->invoke($service, 'user-uuid-123', 1000);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => is_string($message)
                && str_contains($message, 'Dictionary import progress broadcast failed'))
            ->once();
    }

    public function test_control_unguarded_broadcast_would_throw(): void
    {
        // Proves the guard is load-bearing: a raw ShouldBroadcastNow dispatch on
        // the same failing backend propagates the exception.
        $this->useThrowingBroadcaster('throwing_control');

        $this->expectException(\Throwable::class);
        event(new \App\Events\DictionaryImportProgressedEvent('user-uuid-123', 1000));
    }
}
