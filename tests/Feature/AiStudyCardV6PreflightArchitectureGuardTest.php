<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiStudyCardV6PreflightArchitectureGuardTest extends TestCase
{
    public function test_v6_adr_and_preflight_plan_exist(): void
    {
        $adrPath = base_path('docs/adr/ADR-0004-ai-study-card-v6-real-ai-boundary.md');
        $planPath = base_path('docs/plans/ai-study-card-v6-preflight-plan.md');

        $this->assertFileExists($adrPath);
        $this->assertFileExists($planPath);

        $adr = file_get_contents($adrPath);
        $plan = file_get_contents($planPath);

        $this->assertStringContainsString('pre-implementation architecture gate', $adr);
        $this->assertStringContainsString('does not implement V6', $adr);
        $this->assertStringContainsString('AI helps recommend candidate words / phrases', $plan);
        $this->assertStringContainsString('The user still decides what becomes a study card', $plan);
    }

    public function test_v6_boundary_keeps_card_creation_in_user_confirmed_v5_path(): void
    {
        $adr = file_get_contents(base_path('docs/adr/ADR-0004-ai-study-card-v6-real-ai-boundary.md'));
        $plan = file_get_contents(base_path('docs/plans/ai-study-card-v6-preflight-plan.md'));

        $requiredAdrStatements = [
            'AI recommendations are untrusted suggestions',
            'AI recommendations must default to unchecked',
            'AI reason text must not automatically become `sense_zh`',
            'Card creation remains owned by the existing user-confirmed V5 `generate-cards` path',
            'V6 provider calls must not write `ReviewLog`',
            'V6 provider calls must not change `review_cards.fsrs_*`',
        ];

        foreach ($requiredAdrStatements as $statement) {
            $this->assertStringContainsString($statement, $adr);
        }

        $requiredPlanStatements = [
            'V6 must reuse the user-confirmed V4/V5 confirmation path',
            'AI reason remains reference text, not final `sense_zh`',
            'Final card creation still goes through V5 confirmation',
        ];

        foreach ($requiredPlanStatements as $statement) {
            $this->assertStringContainsString($statement, $plan);
        }
    }

    public function test_current_routes_do_not_expose_v6_provider_routes_yet(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $forbiddenRoutes = [
            '/ai-study-card/v6/recommendations/preview',
            '/ai-study-card/v6/recommendations/parse',
            '/ai-study-card/v6',
            'AiStudyCardV6RecommendationController',
        ];

        foreach ($forbiddenRoutes as $route) {
            $this->assertStringNotContainsString($route, $routes, "V6 provider route must not exist in this preflight-only round: {$route}");
        }
    }

    public function test_current_ai_study_card_frontend_has_no_real_provider_calls(): void
    {
        $paths = [
            resource_path('js/components/Text/AiStudyCardDesktopWorkflow.vue'),
            resource_path('js/components/Text/AiStudyCardRecommendationPanel.vue'),
            resource_path('js/components/Text/AiStudyCardPreviewDialog.vue'),
            resource_path('js/components/Text/AiStudyCardGenerateCardsDialog.vue'),
            resource_path('js/services/AiStudyCardPendingWorkflowService.js'),
            resource_path('js/services/AiStudyCardGenerateCardsService.js'),
            resource_path('js/services/AiStudyCardRecommendationParserService.js'),
        ];

        $forbiddenProviderPatterns = [
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.x.ai',
            'https://api.',
            'http://api.',
            'OPENAI_API_KEY',
            'DEEPSEEK_API_KEY',
            'ANTHROPIC_API_KEY',
            'GEMINI_API_KEY',
        ];

        foreach ($paths as $path) {
            $this->assertFileExists($path, "Expected AI Study Card surface file to exist: {$path}");
            $contents = file_get_contents($path);

            foreach ($forbiddenProviderPatterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, basename($path) . " must not call or expose real provider/API-key material in V6 preflight: {$pattern}");
            }
        }
    }

    public function test_backend_ai_study_card_surface_has_no_real_provider_calls_or_key_material(): void
    {
        $paths = [
            app_path('Http/Controllers/AiStudyCardPendingItemController.php'),
            app_path('Services/AiStudyCardPendingItemService.php'),
        ];

        $forbiddenProviderPatterns = [
            'api.openai.com',
            'api.deepseek.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.x.ai',
            'OPENAI_API_KEY',
            'DEEPSEEK_API_KEY',
            'ANTHROPIC_API_KEY',
            'GEMINI_API_KEY',
            'config(\'services.openai',
            'config(\'services.deepseek',
            'config(\'services.anthropic',
            'config(\'services.gemini',
        ];

        foreach ($paths as $path) {
            $this->assertFileExists($path, "Expected AI Study Card backend file to exist: {$path}");
            $contents = file_get_contents($path);

            foreach ($forbiddenProviderPatterns as $pattern) {
                $this->assertStringNotContainsString($pattern, $contents, basename($path) . " must not call or expose real provider/API-key material in V6 preflight: {$pattern}");
            }
        }
    }

    public function test_documentation_index_registers_v6_adr_and_plan(): void
    {
        $index = file_get_contents(base_path('docs/DOCUMENTATION_INDEX.md'));

        $this->assertStringContainsString('ADR-0004-ai-study-card-v6-real-ai-boundary.md', $index);
        $this->assertStringContainsString('ai-study-card-v6-preflight-plan.md', $index);
    }
}
