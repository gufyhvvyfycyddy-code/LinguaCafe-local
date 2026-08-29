<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class H07TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__h07/request-meta', static function (Request $request) {
            return response()->json([
                'ip' => $request->ip(),
                'secure' => $request->isSecure(),
            ]);
        });
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_forwarded_client_headers_are_ignored_without_an_explicit_trusted_proxy(): void
    {
        config()->set('trustedproxy.proxies', null);
        TrustProxies::flushState();

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.20'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.42',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/__h07/request-meta');

        $response->assertOk()->assertJson([
            'ip' => '10.0.0.20',
            'secure' => false,
        ]);
    }

    public function test_explicit_trusted_proxy_resolves_the_forwarded_client_ip_and_scheme(): void
    {
        TrustProxies::flushState();
        TrustProxies::at('10.0.0.10');

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.42',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/__h07/request-meta');

        $response->assertOk()->assertJson([
            'ip' => '203.0.113.42',
            'secure' => true,
        ]);
    }
}
