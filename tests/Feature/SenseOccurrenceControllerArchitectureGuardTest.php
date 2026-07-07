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
        $this->assertStringNotContainsString('private function normalizeList', $controller);

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

    // ================================================================
    // SenseOccurrenceActionController extraction guards.
    // ================================================================

    public function test_sense_occurrence_controller_no_longer_has_single_occurrence_action_methods(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $blockedMethods = [
            'confirm',
            'bind',
            'createSense',
            'reject',
            'ignore',
        ];

        foreach ($blockedMethods as $method) {
            $this->assertStringNotContainsString(
                "public function $method",
                $controller,
                "SenseOccurrenceController must not contain method '$method'"
            );
        }
    }

    public function test_sense_occurrence_controller_no_longer_has_find_occurrence(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $this->assertStringNotContainsString(
            'private function findOccurrence',
            $controller,
            'SenseOccurrenceController must no longer have private findOccurrence'
        );
    }

    public function test_sense_occurrence_action_controller_exists(): void
    {
        $controllerPath = base_path('app/Http/Controllers/SenseOccurrenceActionController.php');
        $this->assertFileExists($controllerPath);
    }

    public function test_sense_occurrence_action_controller_has_required_methods(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceActionController.php'));

        $requiredMethods = [
            'confirm',
            'bind',
            'createSense',
            'reject',
            'ignore',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                "public function $method",
                $controller,
                "SenseOccurrenceActionController must contain method '$method'"
            );
        }
    }

    public function test_sense_occurrence_action_controller_injects_required_services(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceActionController.php'));

        $this->assertStringContainsString(
            'WordSenseOccurrenceService $occurrenceService',
            $controller
        );
        $this->assertStringContainsString(
            'SenseOccurrencePayloadSerializerService $payloadSerializer',
            $controller
        );
    }

    public function test_sense_occurrence_action_controller_has_private_find_occurrence(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceActionController.php'));

        $this->assertStringContainsString(
            'private function findOccurrence',
            $controller,
            'SenseOccurrenceActionController must have private findOccurrence'
        );
        $this->assertStringContainsString(
            'WordSenseOccurrence',
            $controller,
            'findOccurrence must return WordSenseOccurrence'
        );
    }

    public function test_single_occurrence_action_routes_point_to_new_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $routeChecks = [
            "POST /senses/occurrences/{id}/confirm" => "Route::post('/senses/occurrences/{id}/confirm', [App\\Http\\Controllers\\SenseOccurrenceActionController::class, 'confirm'])",
            "POST /senses/occurrences/{id}/bind" => "Route::post('/senses/occurrences/{id}/bind', [App\\Http\\Controllers\\SenseOccurrenceActionController::class, 'bind'])",
            "POST /senses/occurrences/{id}/create-sense" => "Route::post('/senses/occurrences/{id}/create-sense', [App\\Http\\Controllers\\SenseOccurrenceActionController::class, 'createSense'])",
            "POST /senses/occurrences/{id}/reject" => "Route::post('/senses/occurrences/{id}/reject', [App\\Http\\Controllers\\SenseOccurrenceActionController::class, 'reject'])",
            "POST /senses/occurrences/{id}/ignore" => "Route::post('/senses/occurrences/{id}/ignore', [App\\Http\\Controllers\\SenseOccurrenceActionController::class, 'ignore'])",
        ];

        foreach ($routeChecks as $label => $expected) {
            $this->assertStringContainsString(
                $expected,
                $routes,
                "Route '$label' must point to SenseOccurrenceActionController"
            );
        }
    }

    public function test_single_occurrence_action_routes_no_longer_point_to_old_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $blocked = [
            "SenseOccurrenceController::class, 'confirm'",
            "SenseOccurrenceController::class, 'bind'",
            "SenseOccurrenceController::class, 'createSense'",
            "SenseOccurrenceController::class, 'reject'",
            "SenseOccurrenceController::class, 'ignore'",
        ];

        foreach ($blocked as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $routes,
                "Routes must no longer reference SenseOccurrenceController for single occurrence actions: '$pattern'"
            );
        }
    }

    // ================================================================
    // SenseOccurrenceBulkActionController extraction guards.
    // ================================================================

    public function test_sense_occurrence_controller_no_longer_has_bulk_action_methods(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $blockedMethods = [
            'bulkConfirm',
            'bulkIgnore',
            'bulkReject',
            'bulkConfirmHighConfidence',
        ];

        foreach ($blockedMethods as $method) {
            $this->assertStringNotContainsString(
                "public function {$method}",
                $controller,
                "SenseOccurrenceController must no longer contain bulk action method '$method'"
            );
        }
    }

    public function test_sense_occurrence_bulk_action_controller_exists_and_contains_bulk_methods(): void
    {
        $controllerPath = base_path('app/Http/Controllers/SenseOccurrenceBulkActionController.php');

        $this->assertFileExists($controllerPath);

        $controller = file_get_contents($controllerPath);
        $requiredMethods = [
            'bulkConfirm',
            'bulkIgnore',
            'bulkReject',
            'bulkConfirmHighConfidence',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                "public function {$method}",
                $controller,
                "SenseOccurrenceBulkActionController must contain method '$method'"
            );
        }
    }

    public function test_bulk_routes_point_to_bulk_action_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $routeChecks = [
            "POST /senses/occurrences/bulk-confirm" => "Route::post('/senses/occurrences/bulk-confirm', [App\\Http\\Controllers\\SenseOccurrenceBulkActionController::class, 'bulkConfirm'])",
            "POST /senses/occurrences/bulk-ignore" => "Route::post('/senses/occurrences/bulk-ignore', [App\\Http\\Controllers\\SenseOccurrenceBulkActionController::class, 'bulkIgnore'])",
            "POST /senses/occurrences/bulk-reject" => "Route::post('/senses/occurrences/bulk-reject', [App\\Http\\Controllers\\SenseOccurrenceBulkActionController::class, 'bulkReject'])",
            "POST /senses/occurrences/bulk-confirm-high-confidence" => "Route::post('/senses/occurrences/bulk-confirm-high-confidence', [App\\Http\\Controllers\\SenseOccurrenceBulkActionController::class, 'bulkConfirmHighConfidence'])",
        ];

        foreach ($routeChecks as $label => $expected) {
            $this->assertStringContainsString(
                $expected,
                $routes,
                "Bulk route '$label' must point to SenseOccurrenceBulkActionController"
            );
        }
    }

    public function test_routes_no_longer_reference_old_controller_for_bulk_actions(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $blocked = [
            "SenseOccurrenceController::class, 'bulkConfirm'",
            "SenseOccurrenceController::class, 'bulkIgnore'",
            "SenseOccurrenceController::class, 'bulkReject'",
            "SenseOccurrenceController::class, 'bulkConfirmHighConfidence'",
        ];

        foreach ($blocked as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $routes,
                "Routes must no longer reference SenseOccurrenceController for bulk occurrence actions: '$pattern'"
            );
        }
    }

    // ================================================================
    // ManualWordSenseController extraction guards.
    // ================================================================

    public function test_sense_occurrence_controller_no_longer_has_manual_word_sense_methods(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/SenseOccurrenceController.php'));

        $blockedMethods = [
            'storeManualSense',
            'updateManualSense',
            'archiveSense',
        ];

        foreach ($blockedMethods as $method) {
            $this->assertStringNotContainsString(
                "public function {$method}",
                $controller,
                "SenseOccurrenceController must no longer contain manual word sense method '$method'"
            );
        }

        $this->assertStringNotContainsString('WordSenseService', $controller);
        $this->assertStringNotContainsString('private const POS_OPTIONS', $controller);
        $this->assertStringNotContainsString('Illuminate\\Validation\\Rule', $controller);
    }

    public function test_manual_word_sense_controller_exists_and_contains_manual_methods(): void
    {
        $controllerPath = base_path('app/Http/Controllers/ManualWordSenseController.php');

        $this->assertFileExists($controllerPath);

        $controller = file_get_contents($controllerPath);
        $requiredMethods = [
            'storeManualSense',
            'updateManualSense',
            'archiveSense',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                "public function {$method}",
                $controller,
                "ManualWordSenseController must contain method '$method'"
            );
        }

        $this->assertStringContainsString('WordSenseService $wordSenseService', $controller);
        $this->assertStringContainsString('SenseOccurrencePayloadSerializerService $payloadSerializer', $controller);
        $this->assertStringContainsString('private const POS_OPTIONS', $controller);
        $this->assertStringContainsString('normalizeList', $controller);
    }

    public function test_manual_word_sense_routes_point_to_manual_controller(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $routeChecks = [
            "POST /senses/manual" => "Route::post('/senses/manual', [App\\Http\\Controllers\\ManualWordSenseController::class, 'storeManualSense'])",
            "PUT /senses/{id}/manual" => "Route::put('/senses/{id}/manual', [App\\Http\\Controllers\\ManualWordSenseController::class, 'updateManualSense'])",
            "PUT /senses/{id}/archive" => "Route::put('/senses/{id}/archive', [App\\Http\\Controllers\\ManualWordSenseController::class, 'archiveSense'])",
        ];

        foreach ($routeChecks as $label => $expected) {
            $this->assertStringContainsString(
                $expected,
                $routes,
                "Manual word sense route '$label' must point to ManualWordSenseController"
            );
        }
    }

    public function test_routes_no_longer_reference_old_controller_for_manual_word_sense_actions(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $blocked = [
            "SenseOccurrenceController::class, 'storeManualSense'",
            "SenseOccurrenceController::class, 'updateManualSense'",
            "SenseOccurrenceController::class, 'archiveSense'",
        ];

        foreach ($blocked as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $routes,
                "Routes must no longer reference SenseOccurrenceController for manual word sense actions: '$pattern'"
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
