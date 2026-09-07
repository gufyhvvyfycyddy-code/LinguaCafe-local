<?php

namespace Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Session\TokenMismatchException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class LanguageSelectionCsrfMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_put_without_csrf_token_is_rejected_outside_unit_test_mode(): void
    {
        $request = $this->request('PUT');

        $this->expectException(TokenMismatchException::class);

        $this->middleware()->handle($request, fn () => new Response('', 204));
    }

    public function test_put_with_matching_csrf_header_is_allowed(): void
    {
        $request = $this->request('PUT');
        $request->headers->set('X-CSRF-TOKEN', 'r11t-csrf-token');

        $response = $this->middleware()->handle(
            $request,
            fn () => new Response('', 204),
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    #[DataProvider('safeMethods')]
    public function test_safe_methods_are_not_csrf_checked_by_the_middleware(string $method): void
    {
        $response = $this->middleware()->handle(
            $this->request($method),
            fn () => new Response('', 204),
        );

        $this->assertSame(204, $response->getStatusCode());
    }

    public static function safeMethods(): array
    {
        return [
            'GET' => ['GET'],
            'HEAD' => ['HEAD'],
        ];
    }

    private function middleware(): VerifyCsrfToken
    {
        $application = Mockery::mock(Application::class);
        $application->shouldReceive('runningInConsole')->andReturn(false);
        $application->shouldReceive('runningUnitTests')->andReturn(false);

        $middleware = new class($application, new Encrypter(str_repeat('r', 32), 'AES-256-CBC')) extends VerifyCsrfToken
        {
            public function disableResponseCookie(): self
            {
                $this->addHttpCookie = false;

                return $this;
            }
        };

        return $middleware->disableResponseCookie();
    }

    private function request(string $method): Request
    {
        EncryptCookies::flushState();

        $session = new Store(
            'r11t-session',
            new ArraySessionHandler(120),
        );
        $session->start();
        $session->put('_token', 'r11t-csrf-token');

        $request = Request::create('/languages/select/english', $method);
        $request->setLaravelSession($session);

        return $request;
    }
}
