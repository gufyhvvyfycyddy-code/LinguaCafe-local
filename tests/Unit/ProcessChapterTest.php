<?php

namespace Tests\Unit;

use App\Jobs\ProcessChapter;
use App\Services\ChapterService;
use App\Services\QueueStatsService;
use App\Services\VocabularyService;
use ReflectionMethod;
use Tests\TestCase;

class ProcessChapterTest extends TestCase
{
    public function test_process_chapter_uses_container_injected_services(): void
    {
        $job = new ProcessChapter(1, 'test-user-uuid', 1, 'english');
        $handle = new ReflectionMethod($job, 'handle');
        $parameters = $handle->getParameters();

        $this->assertSame(VocabularyService::class, $parameters[0]->getType()->getName());
        $this->assertSame(ChapterService::class, $parameters[1]->getType()->getName());
        $this->assertSame(QueueStatsService::class, $parameters[2]->getType()->getName());
        $this->assertInstanceOf(VocabularyService::class, app(VocabularyService::class));
    }
}
