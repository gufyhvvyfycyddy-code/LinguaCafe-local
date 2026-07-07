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
}
