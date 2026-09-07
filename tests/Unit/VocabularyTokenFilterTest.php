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

    public function test_it_skips_urls_emails_and_file_paths(): void
    {
        $artifacts = [
            'https://example.com/reader?id=42',
            'ftp://files.example.org/book.txt',
            'www.example.com/docs',
            'example.org/docs/chapter',
            'reader@example.com',
            '/var/www/html/index.php',
            'C:\\Users\\reader\\notes.txt',
            './src/App.vue',
            '../storage/logs/laravel.log',
            'src/components/Reader.vue',
        ];

        foreach ($artifacts as $token) {
            $this->assertTrue(VocabularyTokenFilter::shouldSkip($token, 'english'), $token);
        }
    }

    public function test_it_keeps_normal_english_words_and_full_contractions(): void
    {
        foreach (['charge', 'charged', "don't", "we're", "it's", 'brick-and-mortar', 'and/or', 'he/she', 'email', 'path'] as $token) {
            $this->assertFalse(VocabularyTokenFilter::shouldSkip($token, 'english'), $token);
        }
    }
}
