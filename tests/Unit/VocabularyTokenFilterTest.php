<?php

namespace Tests\Unit;

use App\Services\VocabularyTokenFilter;
use Tests\TestCase;

class VocabularyTokenFilterTest extends TestCase
{
    public function test_it_skips_numeric_and_punctuation_tokens(): void
    {
        foreach (['2016', '36', '15.2', '0.8', '15.2%', '-', '.', '"', "'s", "'re", "'ve", "'ll", "'d", "n't", ''] as $token) {
            $this->assertTrue(VocabularyTokenFilter::shouldSkip($token, 'english'), $token);
        }
    }

    public function test_it_excludes_whole_source_references_but_not_surrounding_prose(): void
    {
        foreach (['https://example.org/entry', 'editor@example.org', 'C:/docs/article.txt', 'C:\\\\docs\\\\article.txt'] as $reference) {
            $this->assertTrue(VocabularyTokenFilter::shouldSkip($reference, 'english'), $reference);
        }
        foreach (['entry', 'editor', 'article', 'read'] as $ordinaryWord) {
            $this->assertFalse(VocabularyTokenFilter::shouldSkip($ordinaryWord, 'english'), $ordinaryWord);
        }
    }

    public function test_it_keeps_normal_english_words_and_full_contractions(): void
    {
        foreach (['charge', 'charged', "don't", "we're", "it's", 'brick-and-mortar'] as $token) {
            $this->assertFalse(VocabularyTokenFilter::shouldSkip($token, 'english'), $token);
        }
    }
}
