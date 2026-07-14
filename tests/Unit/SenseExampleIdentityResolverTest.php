<?php

namespace Tests\Unit;

use App\Services\SenseExampleIdentityResolver;
use Tests\TestCase;

class SenseExampleIdentityResolverTest extends TestCase
{
    public function test_identity_resolver_is_a_single_concrete_read_only_boundary(): void
    {
        $this->assertTrue(
            class_exists(SenseExampleIdentityResolver::class),
            'Sense example identity parsing and translation matching need one concrete resolver boundary.',
        );
    }

    public function test_resolves_numeric_and_ai_study_card_sentence_identities(): void
    {
        $resolver = new SenseExampleIdentityResolver();

        $numeric = $resolver->resolve([
            'occurrence_id' => 41,
            'chapter_id' => 12,
            'sentence_id' => '7',
            'sentence_en' => "  A   Stable Sentence. ",
            'is_card_fallback' => false,
        ], 9, 'english');

        $this->assertSame([
            'user_id' => 9,
            'language' => 'english',
            'chapter_id' => 12,
            'occurrence_id' => 41,
            'sentence_id' => '7',
            'sentence_index' => 7,
            'normalized_source_text' => 'a stable sentence.',
            'source_type' => 'occurrence',
        ], $numeric);

        $synthetic = $resolver->resolve([
            'occurrence_id' => 42,
            'chapter_id' => 12,
            'sentence_id' => 'ai-study-card:12:3:8:stable',
            'sentence_en' => 'Synthetic sentence.',
            'is_card_fallback' => false,
        ], 9, 'english');

        $this->assertSame(8, $synthetic['sentence_index']);
        $this->assertSame('ai_study_card', $synthetic['source_type']);
    }

    public function test_rejects_malformed_negative_and_cross_chapter_sentence_identities(): void
    {
        $resolver = new SenseExampleIdentityResolver();
        $base = [
            'occurrence_id' => 42,
            'chapter_id' => 12,
            'sentence_en' => 'Synthetic sentence.',
            'is_card_fallback' => false,
        ];

        foreach ([
            '-1',
            '7x',
            'ai-study-card:12:-1:8:stable',
            'ai-study-card:12:3:-8:stable',
            'ai-study-card:13:3:8:stable',
            'ai-study-card:12:3:8',
            'ai-study-card:12:3:8:stable:extra',
            'ai-study-card:0:3:8:stable',
        ] as $sentenceId) {
            $this->assertNull(
                $resolver->resolve(array_merge($base, ['sentence_id' => $sentenceId]), 9, 'english'),
                "Expected malformed identity to fail closed: {$sentenceId}",
            );
        }
    }

    public function test_translation_priority_is_explicit_then_exact_assist_then_hidden(): void
    {
        $resolver = new SenseExampleIdentityResolver();
        $candidate = [
            'occurrence_id' => 42,
            'chapter_id' => 12,
            'sentence_id' => 'ai-study-card:12:3:8:stable',
            'sentence_en' => 'Synthetic sentence.',
            'sentence_zh' => '  Explicit translation. ',
            'is_card_fallback' => false,
        ];
        $identity = $resolver->resolve($candidate, 9, 'english');
        $assistRows = [
            ['sentence_index' => 8, 'source_text' => 'Synthetic sentence.', 'translation_zh' => 'Assist translation.'],
        ];

        $this->assertSame(
            ['Explicit translation.', 'occurrence'],
            $resolver->translationFor($candidate, $identity, $assistRows),
        );

        $candidate['sentence_zh'] = null;
        $this->assertSame(
            ['Assist translation.', 'chapter_ai_reading_assist'],
            $resolver->translationFor($candidate, $identity, $assistRows),
        );

        $ambiguous = [...$assistRows, ...$assistRows];
        $this->assertSame([null, null], $resolver->translationFor($candidate, $identity, $ambiguous));
        $this->assertSame([null, null], $resolver->translationFor(
            $candidate,
            $identity,
            [['sentence_index' => 8, 'source_text' => 'Another sentence.', 'translation_zh' => 'Wrong.']],
        ));
    }

    public function test_card_fallback_explicit_translation_has_its_own_source(): void
    {
        $resolver = new SenseExampleIdentityResolver();
        $candidate = [
            'occurrence_id' => null,
            'chapter_id' => 12,
            'sentence_id' => 4,
            'sentence_en' => 'Fallback sentence.',
            'sentence_zh' => 'Fallback translation.',
            'is_card_fallback' => true,
        ];

        $this->assertSame(
            ['Fallback translation.', 'card_fallback'],
            $resolver->translationFor($candidate, $resolver->resolve($candidate, 9, 'english'), []),
        );
    }
}
