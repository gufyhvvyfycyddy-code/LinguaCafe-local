<?php

namespace Tests\Unit;

use App\Services\ReadingChapterTextService;
use PHPUnit\Framework\TestCase;

class AiReadingAssistV2OccurrenceIdentityTest extends TestCase
{
    public function test_occurrence_identity_is_stable_and_uses_every_frozen_owner_field(): void
    {
        $service = new ReadingChapterTextService();
        $identity = [
            'userId' => 7,
            'language' => 'english',
            'chapterId' => 11,
            'sourceRevision' => 'sha256:current-source',
            'kind' => 'word',
            'startWordIndex' => 23,
            'endWordIndex' => 23,
        ];

        $occurrenceId = $service->occurrenceId(...$identity);

        $this->assertSame($occurrenceId, $service->occurrenceId(...$identity));
        $this->assertMatchesRegularExpression('/^occ2_[a-f0-9]{64}$/D', $occurrenceId);

        foreach ([
            'userId' => 8,
            'language' => 'german',
            'chapterId' => 12,
            'sourceRevision' => 'sha256:changed-source',
            'kind' => 'phrase',
            'startWordIndex' => 22,
            'endWordIndex' => 24,
        ] as $field => $changedValue) {
            $changedIdentity = $identity;
            $changedIdentity[$field] = $changedValue;

            $this->assertNotSame(
                $occurrenceId,
                $service->occurrenceId(...$changedIdentity),
                "Changing {$field} must invalidate the occurrence identity.",
            );
        }
    }
}
