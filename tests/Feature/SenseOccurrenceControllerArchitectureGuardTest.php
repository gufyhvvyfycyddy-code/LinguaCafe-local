<?php

namespace Tests\Feature;

use Tests\TestCase;

class SenseOccurrenceControllerArchitectureGuardTest extends TestCase
{
    public function test_sense_occurrence_payload_shape_lives_in_serializer_service(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));
        $serializer = file_get_contents(base_path('app/Services/SenseOccurrencePayloadSerializerService.php'));

        $this->assertStringContainsString('SenseOccurrencePayloadSerializerService $payloadSerializer', $controller);
        $this->assertStringContainsString('return $this->payloadSerializer->serializeOccurrence($occurrence);', $controller);
        $this->assertStringContainsString('return $this->payloadSerializer->serializeSense($sense);', $controller);
        $this->assertStringContainsString('return $this->payloadSerializer->normalizeList($value);', $controller);

        $controllerTail = substr($controller, strpos($controller, 'private function serializeOccurrence'));
        $this->assertStringNotContainsString("'occurrence_id' =>", $controllerTail);
        $this->assertStringNotContainsString("'sense_id' =>", $controllerTail);
        $this->assertStringNotContainsString("array_map('trim'", $controllerTail);

        $this->assertStringContainsString("'occurrence_id' =>", $serializer);
        $this->assertStringContainsString("'sense_id' =>", $serializer);
        $this->assertStringContainsString("array_map('trim'", $serializer);
    }

    public function test_examples_method_delegates_to_service(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $this->assertStringContainsString('SenseOccurrenceExampleService $exampleService', $controller);

        $this->assertStringContainsString(
            "\$this->exampleService->getExamples(\$userId, \$language, \$id)",
            $controller
        );
    }

    public function test_controller_no_longer_assembles_example_payload(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $examplesMethod = $this->extractMethodBody($controller, 'public function examples');

        $this->assertStringNotContainsString('WordSense::where', $examplesMethod);
        $this->assertStringNotContainsString('WordSenseOccurrence::where', $examplesMethod);
        $this->assertStringNotContainsString("'sense_id' =>", $examplesMethod);
        $this->assertStringNotContainsString("'lemma' =>", $examplesMethod);
        $this->assertStringNotContainsString("'occurrence_id' =>", $examplesMethod);
        $this->assertStringNotContainsString("'sentence_en' =>", $examplesMethod);
        $this->assertStringNotContainsString("'sentence_zh' =>", $examplesMethod);
        $this->assertStringNotContainsString("'surface' =>", $examplesMethod);
        $this->assertStringNotContainsString("'chapter_id' =>", $examplesMethod);
        $this->assertStringNotContainsString("'status' =>", $examplesMethod);
        $this->assertStringNotContainsString("'created_at' =>", $examplesMethod);
        $this->assertStringNotContainsString("->toISOString()", $examplesMethod);
        $this->assertStringNotContainsString("whereNotNull('sentence_en')", $examplesMethod);
        $this->assertStringNotContainsString("orderBy('created_at', 'desc')", $examplesMethod);
        $this->assertStringNotContainsString("limit(20)", $examplesMethod);
    }

    public function test_example_payload_shapes_live_in_example_service(): void
    {
        $service = file_get_contents(base_path('app/Services/SenseOccurrenceExampleService.php'));

        $this->assertStringContainsString("'sense_id' =>", $service);
        $this->assertStringContainsString("'lemma' =>", $service);
        $this->assertStringContainsString("'occurrence_id' =>", $service);
        $this->assertStringContainsString("'sentence_en' =>", $service);
        $this->assertStringContainsString("'sentence_zh' =>", $service);
        $this->assertStringContainsString("'surface' =>", $service);
        $this->assertStringContainsString("'chapter_id' =>", $service);
        $this->assertStringContainsString("'status' =>", $service);
        $this->assertStringContainsString("'created_at' =>", $service);
        $this->assertStringContainsString("->toISOString()", $service);
        $this->assertStringContainsString("whereNotNull('sentence_en')", $service);
        $this->assertStringContainsString("orderBy('created_at', 'desc')", $service);
        $this->assertStringContainsString("limit(20)", $service);
    }

    // ================================================================
    // ReadingInlineSenseConfirmationController extraction guards.
    // ================================================================

    public function test_sense_occurrence_controller_no_longer_imports_inline_confirmation_service(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $this->assertStringNotContainsString(
            'ReadingInlineSenseConfirmationService',
            $controller,
            'SenseOccurrenceController must no longer import or inject ReadingInlineSenseConfirmationService'
        );
    }

    public function test_sense_occurrence_controller_no_longer_has_inline_confirmation_methods(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $blockedMethods = [
            'storeInlineConfirmation',
            'listInlineConfirmations',
            'revokeInlineConfirmation',
            'undoInlineConfirmation',
        ];

        foreach ($blockedMethods as $method) {
            $this->assertStringNotContainsString(
                "function $method",
                $controller,
                "SenseOccurrenceController must not contain method '$method'"
            );
        }
    }

    public function test_reading_inline_confirmation_controller_exists_and_has_required_methods(): void
    {
        $controllerPath = base_path('app/Http/Controllers/ReadingInlineSenseConfirmationController.php');
        $this->assertFileExists($controllerPath);

        $controller = file_get_contents($controllerPath);

        $requiredMethods = [
            'storeInlineConfirmation',
            'listInlineConfirmations',
            'revokeInlineConfirmation',
            'undoInlineConfirmation',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                "function $method",
                $controller,
                "ReadingInlineSenseConfirmationController must contain method '$method'"
            );
        }
    }

    public function test_reading_inline_confirmation_controller_injects_required_services(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/ReadingInlineSenseConfirmationController.php'));

        $this->assertStringContainsString(
            'ReadingInlineSenseConfirmationService $inlineConfirmationService',
            $controller
        );
        $this->assertStringContainsString(
            'WordSenseKnownSenseService $knownSenseService',
            $controller
        );
    }

    public function test_inline_confirmation_routes_point_to_new_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $routeChecks = [
            "POST /senses/inline-confirmation" => "Route::post('/senses/inline-confirmation', [App\\Http\\Controllers\\ReadingInlineSenseConfirmationController::class, 'storeInlineConfirmation'])",
            "GET /senses/inline-confirmations" => "Route::get('/senses/inline-confirmations', [App\\Http\\Controllers\\ReadingInlineSenseConfirmationController::class, 'listInlineConfirmations'])",
            "POST /senses/inline-confirmations/undo" => "Route::post('/senses/inline-confirmations/undo', [App\\Http\\Controllers\\ReadingInlineSenseConfirmationController::class, 'undoInlineConfirmation'])",
            "DELETE /senses/inline-confirmations/{id}" => "Route::delete('/senses/inline-confirmations/{id}', [App\\Http\\Controllers\\ReadingInlineSenseConfirmationController::class, 'revokeInlineConfirmation'])",
        ];

        foreach ($routeChecks as $label => $expected) {
            $this->assertStringContainsString(
                $expected,
                $routes,
                "Route '$label' must point to ReadingInlineSenseConfirmationController"
            );
        }
    }

    public function test_inline_confirmation_routes_no_longer_point_to_old_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $blocked = [
            "SenseOccurrenceController::class, 'storeInlineConfirmation'",
            "SenseOccurrenceController::class, 'listInlineConfirmations'",
            "SenseOccurrenceController::class, 'revokeInlineConfirmation'",
            "SenseOccurrenceController::class, 'undoInlineConfirmation'",
        ];

        foreach ($blocked as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $routes,
                "Routes must no longer reference SenseOccurrenceController for inline confirmation: '$pattern'"
            );
        }
    }

    // ================================================================
    // SenseSourceContextController extraction guards.
    // ================================================================

    public function test_sense_occurrence_controller_no_longer_has_source_context_methods(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $blockedMethods = [
            'sourceContext',
            'sourceContextList',
        ];

        foreach ($blockedMethods as $method) {
            $this->assertStringNotContainsString(
                "function $method",
                $controller,
                "SenseOccurrenceController must not contain method '$method'"
            );
        }
    }

    public function test_sense_occurrence_controller_no_longer_imports_sense_source_context_service(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $this->assertStringNotContainsString(
            'SenseSourceContextService',
            $controller,
            'SenseOccurrenceController must no longer import or inject SenseSourceContextService'
        );
    }

    public function test_sense_source_context_controller_exists_and_has_required_methods(): void
    {
        $controllerPath = base_path('app/Http/Controllers/SenseSourceContextController.php');
        $this->assertFileExists($controllerPath);

        $controller = file_get_contents($controllerPath);

        $requiredMethods = [
            'sourceContext',
            'sourceContextList',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                "function $method",
                $controller,
                "SenseSourceContextController must contain method '$method'"
            );
        }
    }

    public function test_source_context_routes_point_to_new_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $routeChecks = [
            "GET /senses/{id}/source-context" => "Route::get('/senses/{id}/source-context', [App\\Http\\Controllers\\SenseSourceContextController::class, 'sourceContext'])",
            "GET /senses/{id}/source-context-list" => "Route::get('/senses/{id}/source-context-list', [App\\Http\\Controllers\\SenseSourceContextController::class, 'sourceContextList'])",
        ];

        foreach ($routeChecks as $label => $expected) {
            $this->assertStringContainsString(
                $expected,
                $routes,
                "Route '$label' must point to SenseSourceContextController"
            );
        }
    }

    public function test_source_context_routes_no_longer_point_to_old_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $blocked = [
            "SenseOccurrenceController::class, 'sourceContext'",
            "SenseOccurrenceController::class, 'sourceContextList'",
        ];

        foreach ($blocked as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $routes,
                "Routes must no longer reference SenseOccurrenceController for source context: '$pattern'"
            );
        }
    }

    private function extractMethodBody(string $code, string $methodSignature): string
    {
        $pos = strpos($code, $methodSignature);
        if ($pos === false) {
            return '';
        }

        $start = strpos($code, '{', $pos);
        if ($start === false) {
            return '';
        }

        $depth = 0;
        $end = $start;
        for ($i = $start; $i < strlen($code); $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }

        return substr($code, $start, $end - $start);
    }
}
