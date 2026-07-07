<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiStudyCardV6RealProviderPlanGuardTest extends TestCase
{
    public function test_real_provider_adr_plan_and_network_playbook_exist(): void
    {
        $this->assertFileExists(base_path('docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md'));
        $this->assertFileExists(base_path('docs/plans/ai-study-card-v6-real-provider-implementation-plan.md'));
        $this->assertFileExists(base_path('docs/testing/ai-study-card-v6-real-provider-network-smoke-playbook.md'));
    }

    public function test_real_provider_plan_does_not_authorize_live_calls_or_secrets(): void
    {
        $adr = file_get_contents(base_path('docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md'));
        $plan = file_get_contents(base_path('docs/plans/ai-study-card-v6-real-provider-implementation-plan.md'));

        $required = [
            'does not implement a live provider',
            'does not add a UI trigger',
            'does not add a secret',
            'does not authorize external requests',
            'V5 card generation remains the only card creation path',
        ];

        foreach ($required as $needle) {
            $this->assertStringContainsString($needle, $adr, "ADR-0005 must explicitly keep live-provider work gated: {$needle}");
        }

        $planRequired = [
            'Frozen plan. V6-5 backend provider-preview skeleton implemented disabled/fail-closed.',
            'Still not implemented:',
            'real provider adapter',
            'provider UI trigger',
            'secret storage',
            'external requests',
        ];

        foreach ($planRequired as $needle) {
            $this->assertStringContainsString($needle, $plan, "Real-provider plan must stay plan-only: {$needle}");
        }
    }

    public function test_provider_route_skeleton_is_allowed_only_as_disabled_backend_boundary(): void
    {
        $adr = file_get_contents(base_path('docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(app_path('Http/Controllers/AiStudyCardV6RecommendationController.php'));

        $futureRoute = '/ai-study-card/v6/recommendations/provider-preview';

        $this->assertStringContainsString($futureRoute, $adr);
        $this->assertStringContainsString($futureRoute, $routes);
        $this->assertStringContainsString('providerPreview', $controller);
        $this->assertStringContainsString('providerPreviewService->preview', $controller);
    }

    public function test_network_playbook_requires_browser_validation_and_forbids_api_substitution(): void
    {
        $playbook = file_get_contents(base_path('docs/testing/ai-study-card-v6-real-provider-network-smoke-playbook.md'));

        $required = [
            'API tests, curl, route checks, screenshots, and code review do not replace this smoke',
            'provider call happens on page load',
            'provider call happens on token click',
            'frontend calls provider domain directly',
            'Network exposes a secret value',
            'test substitutes API/curl for browser Network validation',
            'Expert used: 网页端体验师',
        ];

        foreach ($required as $needle) {
            $this->assertStringContainsString($needle, $playbook, "Network smoke playbook must require real browser validation: {$needle}");
        }
    }

    public function test_plan_keeps_live_provider_out_of_existing_v1_to_v5_routes(): void
    {
        $adr = file_get_contents(base_path('docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md'));

        $forbiddenShortcuts = [
            '/ai-study-card/v6/recommendations/request-package',
            '/ai-study-card/pending-items/preview-package',
            '/ai-study-card/pending-items/final-candidates-package',
            '/ai-study-card/generate-cards',
            '/senses/inline-preview',
        ];

        foreach ($forbiddenShortcuts as $route) {
            $this->assertStringContainsString($route, $adr, "ADR must explicitly forbid live provider shortcut through {$route}");
        }
    }

    public function test_plan_files_contain_no_secret_values_or_env_key_names(): void
    {
        $paths = [
            base_path('docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md'),
            base_path('docs/plans/ai-study-card-v6-real-provider-implementation-plan.md'),
            base_path('docs/testing/ai-study-card-v6-real-provider-network-smoke-playbook.md'),
        ];

        $forbidden = [
            'OPENAI_API_KEY',
            'DEEPSEEK_API_KEY',
            'ANTHROPIC_API_KEY',
            'GEMINI_API_KEY',
            'sk-',
            'Bearer ',
            'env(',
            'api.openai.com/v1',
            'api.deepseek.com/',
            'api.anthropic.com/',
            'generativelanguage.googleapis.com/v1',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $contents, basename($path) . " must not contain secret material or live provider config: {$needle}");
            }
        }
    }
}
