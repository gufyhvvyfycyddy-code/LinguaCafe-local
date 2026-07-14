<?php

namespace Tests\Feature;

use App\Models\ReviewCard;
use App\Models\User;
use App\Models\WordSense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 2000-22 — Phase 4B CustomStudy routes registration tests.
 *
 * Verifies §19.7 items 1, 2, 3, 4, 5, 15, 16, 17:
 *  1. Three POST routes exist.
 *  2. All in auth middleware.
 *  3. Not admin-only.
 *  4. Unauthenticated → 401 on all three.
 *  5. GET → 405 on all three.
 *  15. No fourth Custom Study session route.
 *  16. No token URL route.
 *  17. No exclude query param route.
 *
 * Frozen route contract (§18):
 *   POST /custom-study/sessions          -> openSession
 *   POST /custom-study/sessions/answer   -> answer
 *   POST /custom-study/sessions/resume   -> resume
 *
 * Forbidden routes (must NOT exist):
 *   GET  /custom-study/sessions/next
 *   ANY  /custom-study/sessions/{token}
 *   ANY  /custom-study/sessions?exclude=card_id
 *   DELETE /custom-study/sessions/*
 * The read-only chapter-options endpoint is intentionally outside the
 * three-route session contract.
 */
class CustomStudyRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate([
            'name' => 'Routes User',
            'email' => 'routes-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
            'is_admin' => false,
        ]);

        $this->adminUser = User::forceCreate([
            'name' => 'Routes Admin',
            'email' => 'routes-admin-' . Str::uuid() . '@example.com',
            'password' => Hash::make('password'),
            'selected_language' => 'english',
            'password_changed' => true,
            'uuid' => (string) Str::uuid(),
            'is_admin' => true,
        ]);
    }

    // ─── 1. Three POST routes exist ───

    public function test_open_session_post_route_exists(): void
    {
        $this->assertTrue(
            Route::has('custom-study.sessions.open')
            || $this->routeExists('POST', '/custom-study/sessions'),
            'POST /custom-study/sessions route must exist.'
        );
    }

    public function test_answer_post_route_exists(): void
    {
        $this->assertTrue(
            $this->routeExists('POST', '/custom-study/sessions/answer'),
            'POST /custom-study/sessions/answer route must exist.'
        );
    }

    public function test_resume_post_route_exists(): void
    {
        $this->assertTrue(
            $this->routeExists('POST', '/custom-study/sessions/resume'),
            'POST /custom-study/sessions/resume route must exist.'
        );
    }

    // ─── 2 & 4. Auth middleware: guest → 401 ───

    public function test_guest_open_session_returns_401(): void
    {
        $this->postJson('/custom-study/sessions', [])->assertStatus(401);
    }

    public function test_guest_answer_returns_401(): void
    {
        $this->postJson('/custom-study/sessions/answer', ['token' => 'x', 'rating' => 'good'])
            ->assertStatus(401);
    }

    public function test_guest_resume_returns_401(): void
    {
        $this->postJson('/custom-study/sessions/resume', ['token' => 'x'])
            ->assertStatus(401);
    }

    // ─── 3. Not admin-only: regular user does NOT get 403 ───

    public function test_non_admin_open_session_not_forbidden(): void
    {
        // Regular authenticated user should NOT get 403 (admin-only).
        // An empty-body POST may yield 422, but NOT 403.
        $response = $this->actingAs($this->user)->postJson('/custom-study/sessions', []);
        $this->assertNotSame(403, $response->status(), 'Non-admin user must not get 403 on /custom-study/sessions.');
    }

    public function test_non_admin_answer_not_forbidden(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/custom-study/sessions/answer', ['token' => 'x', 'rating' => 'good']);
        $this->assertNotSame(403, $response->status(), 'Non-admin user must not get 403 on answer.');
    }

    public function test_non_admin_resume_not_forbidden(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/custom-study/sessions/resume', ['token' => 'x']);
        $this->assertNotSame(403, $response->status(), 'Non-admin user must not get 403 on resume.');
    }

    // ─── 5. GET → 405 ───

    public function test_get_open_session_returns_405(): void
    {
        $this->actingAs($this->user)->getJson('/custom-study/sessions')
            ->assertStatus(405);
    }

    public function test_get_answer_returns_405(): void
    {
        $this->actingAs($this->user)->getJson('/custom-study/sessions/answer')
            ->assertStatus(405);
    }

    public function test_get_resume_returns_405(): void
    {
        $this->actingAs($this->user)->getJson('/custom-study/sessions/resume')
            ->assertStatus(405);
    }

    // ─── 15. No fourth Custom Study session route ───

    public function test_no_fourth_custom_study_session_route(): void
    {
        $sessionRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(function (\Illuminate\Routing\Route $r) {
                $uri = $r->uri();
                // Match /custom-study/sessions and any direct sub-path.
                return str_starts_with($uri, 'custom-study/sessions');
            })
            ->map(fn (\Illuminate\Routing\Route $r) => $r->methods()[0] . ' ' . $r->uri())
            ->values()
            ->all();

        // Exactly three session routes — no fourth.
        $this->assertCount(
            3,
            $sessionRoutes,
            'Expected exactly 3 custom-study/sessions routes. Found: ' . implode(', ', $sessionRoutes)
        );
    }

    // ─── 16. No token URL route (no /custom-study/sessions/{token}) ───

    public function test_no_token_url_route(): void
    {
        $tokenUrlRoute = collect(Route::getRoutes()->getRoutes())
            ->contains(function (\Illuminate\Routing\Route $r) {
                $uri = $r->uri();
                // A path-param route like custom-study/sessions/{token} or
                // custom-study/sessions/{id} would indicate a token URL.
                return str_starts_with($uri, 'custom-study/sessions/')
                    && preg_match('#\{[^}]+\}#', $uri);
            });

        $this->assertFalse(
            $tokenUrlRoute,
            'No /custom-study/sessions/{token} URL route allowed — token must be in body only.'
        );
    }

    // ─── 17. No exclude query param route ───

    public function test_no_exclude_query_param_route(): void
    {
        // No route should use 'exclude' as a query-derived path or required param.
        $excludeRoute = collect(Route::getRoutes()->getRoutes())
            ->contains(function (\Illuminate\Routing\Route $r) {
                $uri = $r->uri();
                return str_contains($uri, 'custom-study')
                    && str_contains($uri, 'exclude');
            });

        $this->assertFalse(
            $excludeRoute,
            'No custom-study route with exclude query/path param allowed.'
        );
    }

    public function test_chapter_options_route_exists_without_expanding_session_routes(): void
    {
        $this->assertTrue(
            $this->routeExists('GET', '/custom-study/chapter-options'),
            'GET /custom-study/chapter-options must remain a read-only setup endpoint.'
        );
    }

    public function test_no_delete_session_route(): void
    {
        $deleteRoute = collect(Route::getRoutes()->getRoutes())
            ->contains(function (\Illuminate\Routing\Route $r) {
                return in_array('DELETE', $r->methods(), true)
                    && str_starts_with($r->uri(), 'custom-study/sessions');
            });

        $this->assertFalse(
            $deleteRoute,
            'No DELETE custom-study/sessions route allowed.'
        );
    }

    // ─── Helpers ───

    private function routeExists(string $method, string $uri): bool
    {
        return collect(Route::getRoutes()->getRoutes())
            ->contains(function (\Illuminate\Routing\Route $r) use ($method, $uri) {
                $methods = $r->methods();
                // HEAD is auto-added for GET; ignore it for matching.
                $methods = array_filter($methods, fn ($m) => $m !== 'HEAD');
                return in_array($method, $methods, true)
                    && '/' . ltrim($r->uri(), '/') === $uri;
            });
    }
}
