<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebHttpErrorRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => true]);

        Route::middleware('web')->get('/__testing/web-error-403', function () {
            abort(403);
        });
    }

    public function test_post_only_routes_render_safe_branded_405_pages(): void
    {
        foreach (['/reviews', '/import'] as $path) {
            $response = $this->get($path);

            $this->assertSafeBrandedHtmlError($response, 405);
            $response->assertHeader('Allow', 'POST');
        }
    }

    public function test_unknown_web_route_renders_safe_branded_404_page(): void
    {
        $this->assertSafeBrandedHtmlError(
            $this->get('/__testing/missing-web-page'),
            404,
        );
    }

    public function test_forbidden_web_route_renders_safe_branded_403_page(): void
    {
        $this->assertSafeBrandedHtmlError(
            $this->get('/__testing/web-error-403'),
            403,
            false,
        );
    }

    public function test_non_mobile_json_errors_are_not_replaced_by_branded_html(): void
    {
        $this->getJson('/__testing/missing-web-page')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertDontSee('返回首页');

        $this->getJson('/reviews')
            ->assertMethodNotAllowed()
            ->assertHeader('Allow', 'POST')
            ->assertHeader('Content-Type', 'application/json')
            ->assertDontSee('返回首页');
    }

    public function test_xhr_errors_are_not_replaced_by_branded_html(): void
    {
        $this->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => '*/*',
        ])->get('/reviews')
            ->assertMethodNotAllowed()
            ->assertHeader('Allow', 'POST')
            ->assertDontSee('返回首页');
    }

    public function test_auth_redirects_are_not_replaced_by_error_pages(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }

    public function test_mobile_http_error_contract_is_unchanged(): void
    {
        $this->getJson('/api/v1/mobile/__testing/missing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    private function assertSafeBrandedHtmlError($response, int $status, bool $assertNoSetCookie = true): void
    {
        $response
            ->assertStatus($status)
            ->assertSee('LinguaCafe')
            ->assertSee('返回首页')
            ->assertDontSee('Exception', false)
            ->assertDontSee('vendor\\laravel', false)
            ->assertDontSee('laravel_session', false)
            ->assertDontSee('XSRF-TOKEN', false)
            ->assertDontSee('Request', false)
            ->assertDontSee('Headers', false);

        if ($assertNoSetCookie) {
            $this->assertFalse(
                $response->headers->has('Set-Cookie'),
                'Routing-level error responses must not create session cookies.',
            );
        }
    }
}
