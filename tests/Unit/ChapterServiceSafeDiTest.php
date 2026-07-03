<?php

namespace Tests\Unit;

use App\Services\BookService;
use App\Services\ChapterService;
use App\Services\GoalService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Characterization test for the safe DI optimization introduced in
 * GLM-ArchitectureFirst1000-SafeStability-1 (sub-stage 7).
 *
 * Previously ChapterService instantiated BookService and GoalService
 * inline via `new`. They are now resolved through the Laravel container.
 * This test asserts that:
 *
 *   1. The constructor declares BookService and GoalService as
 *      container-injectable dependencies (no `new` inside the constructor).
 *   2. The Laravel container can resolve ChapterService with both
 *      dependencies auto-injected.
 *   3. The injected instances are the same singleton-shape that the
 *      container would resolve on its own (i.e. real service classes,
 *      not stubs).
 *   4. finishChapter() no longer references `new GoalService()` and
 *      processChapterText()/deleteChapter() no longer reference
 *      `new BookService()`.
 *
 * This test does NOT exercise chapter processing logic, tokenizer
 * behavior, or any write path. It only characterizes the DI shape so
 * a future regression (e.g. re-introducing `new BookService()` in the
 * constructor) is caught.
 */
class ChapterServiceSafeDiTest extends TestCase
{
    public function test_constructor_declares_book_service_and_goal_service_dependencies(): void
    {
        $reflection = new ReflectionClass(ChapterService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'ChapterService must have a constructor.');

        $parameters = $constructor->getParameters();
        $this->assertCount(2, $parameters, 'ChapterService constructor must declare exactly two dependencies.');

        $this->assertSame(
            BookService::class,
            $parameters[0]->getType()->getName(),
            'First constructor parameter must be BookService.'
        );
        $this->assertSame(
            GoalService::class,
            $parameters[1]->getType()->getName(),
            'Second constructor parameter must be GoalService.'
        );
    }

    public function test_container_resolves_chapter_service_with_dependencies_injected(): void
    {
        $service = app(ChapterService::class);

        $this->assertInstanceOf(ChapterService::class, $service);

        $reflection = new ReflectionClass($service);
        $bookProp = $reflection->getProperty('bookService');
        $bookProp->setAccessible(true);
        $goalProp = $reflection->getProperty('goalService');
        $goalProp->setAccessible(true);

        $this->assertInstanceOf(
            BookService::class,
            $bookProp->getValue($service),
            'bookService must be injected by the container, not instantiated inline.'
        );
        $this->assertInstanceOf(
            GoalService::class,
            $goalProp->getValue($service),
            'goalService must be injected by the container, not instantiated inline.'
        );
    }

    public function test_constructor_body_does_not_call_new_on_book_or_goal_service(): void
    {
        // Read the source file directly to assert that the constructor
        // body does not reintroduce `new BookService()` / `new GoalService()`.
        $source = file_get_contents((new ReflectionClass(ChapterService::class))->getFileName());

        $constructorStart = strpos($source, 'public function __construct(');
        $this->assertNotFalse($constructorStart, 'Constructor must exist in source.');

        // Capture from constructor signature up to the matching closing brace.
        $braceOpen = strpos($source, '{', $constructorStart);
        $this->assertNotFalse($braceOpen, 'Constructor body must open with {.');

        $depth = 0;
        $end = $braceOpen;
        for ($i = $braceOpen; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $constructorBody = substr($source, $braceOpen, $end - $braceOpen);

        $this->assertStringNotContainsString(
            'new BookService(',
            $constructorBody,
            'Constructor body must not call `new BookService()`.'
        );
        $this->assertStringNotContainsString(
            'new GoalService(',
            $constructorBody,
            'Constructor body must not call `new GoalService()`.'
        );
    }

    public function test_finish_chapter_does_not_call_new_goal_service(): void
    {
        $reflection = new ReflectionMethod(ChapterService::class, 'finishChapter');
        $source = file_get_contents($reflection->getFileName());
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        $lines = explode("\n", $source);
        $methodBody = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertStringNotContainsString(
            'new GoalService(',
            $methodBody,
            'finishChapter() must use injected goalService, not `new GoalService()`.'
        );
    }

    public function test_process_chapter_text_does_not_call_new_book_service(): void
    {
        $reflection = new ReflectionMethod(ChapterService::class, 'processChapterText');
        $source = file_get_contents($reflection->getFileName());
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        $lines = explode("\n", $source);
        $methodBody = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertStringNotContainsString(
            'new BookService(',
            $methodBody,
            'processChapterText() must use injected bookService, not `new BookService()`.'
        );
    }

    public function test_delete_chapter_does_not_call_new_book_service(): void
    {
        $reflection = new ReflectionMethod(ChapterService::class, 'deleteChapter');
        $source = file_get_contents($reflection->getFileName());
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        $lines = explode("\n", $source);
        $methodBody = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

        $this->assertStringNotContainsString(
            'new BookService(',
            $methodBody,
            'deleteChapter() must use injected bookService, not `new BookService()`.'
        );
    }
}
