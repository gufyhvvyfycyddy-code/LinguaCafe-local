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

    public function test_real_provider_plan_and_current_gate_do_not_authorize_live_calls_or_secrets(): void
    {
        $adr = file_get_contents(base_path('docs/adr/ADR-0005-ai-study-card-v6-real-provider-implementation-plan.md'));
        $plan = file_get_contents(base_path('docs/plans/ai-study-card-v6-real-provider-implementation-plan.md'));
        $currentGate = file_get_contents(base_path('docs/adr/ADR-0030-ai-study-card-v6-default-off-provider-gate.md'));

        $requiredAdrStatements = [
            'Implementation-status statements are superseded by ADR-0030',
            'Neither ADR authorizes external requests',
            'live external provider calls',
            'API keys or secret values',
            'V5 card generation remains the only card creation path',
        ];

        foreach ($requiredAdrStatements as $needle) {
            $this->assertStringContainsString($needle, $adr, "ADR-0005 must retain the provider safety boundary: {$needle}");
        }

        $requiredCurrentGateStatements = [
            'default-off / fail-closed implementation gate',
            'Production defaults bind `AiStudyCardV6DisabledProviderAdapter`',
            'Closing the implementation gate does not authorize',
            'enabling external requests in any environment',
            'supplying, reading, editing, moving, or logging a secret',
            'modifying `.env`',
            'Card creation remains the existing V5 path',
            'Provider preview may not create WordSense, ReviewCard, ReviewLog, legacy word cards, or change FSRS.',
        ];

        foreach ($requiredCurrentGateStatements as $needle) {
            $this->assertStringContainsString($needle, $currentGate, "ADR-0030 must keep runtime activation and learning writes gated: {$needle}");
        }

        $requiredHistoricalPlanStatements = [
            'Historical implementation plan.',
            'Current implementation status is superseded by `ADR-0030`',
            'safety and runtime-activation constraints remain active',
            'No real external requests in automated tests.',
            'never expose secret values',
            'never write learning data',
        ];

        foreach ($requiredHistoricalPlanStatements as $needle) {
            $this->assertStringContainsString($needle, $plan, "Historical provider plan must point to the current gate and retain safety constraints: {$needle}");
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
            base_path('docs/adr/ADR-0030-ai-study-card-v6-default-off-provider-gate.md'),
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
                $this->assertStringNotContainsString($needle, $contents, basename($path)." must not contain secret material or live provider config: {$needle}");
            }
        }
    }
}
