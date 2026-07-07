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
