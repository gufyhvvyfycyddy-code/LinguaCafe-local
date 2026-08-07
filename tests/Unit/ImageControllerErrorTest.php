<?php

namespace Tests\Unit;

use App\Http\Controllers\ImageController;
use App\Http\Requests\Images\GetBookImageRequest;
use App\Services\ImageService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ImageControllerErrorTest extends TestCase
{
    public function test_book_image_storage_failure_does_not_expose_internal_exception_text(): void
    {
        Log::spy();
        Auth::shouldReceive('user')->twice()->andReturn((object) [
            'id' => 42,
            'selected_language' => 'english',
        ]);

        $images = $this->createMock(ImageService::class);
        $images->expects($this->once())
            ->method('getBookImage')
            ->with(42, 'english', 'cover.jpg')
            ->willThrowException(new \RuntimeException('failed at C:\\private\\book-covers\\cover.jpg with secret details'));

        $request = new GetBookImageRequest();
        $request->setRouteResolver(fn () => new class {
            public function parameter(string $name): ?string
            {
                return $name === 'fileName' ? 'cover.jpg' : null;
            }
        });

        try {
            (new ImageController($images))->getBookImage('cover.jpg', $request);
            $this->fail('Expected an HTTP exception.');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
            $this->assertSame('Book image could not be loaded.', $exception->getMessage());
            $this->assertStringNotContainsString('private', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')->once()->with(
            'book_image_load_failed',
            ['exception' => \RuntimeException::class],
        );
    }

    public function test_book_image_not_found_semantics_are_preserved(): void
    {
        Auth::shouldReceive('user')->twice()->andReturn((object) [
            'id' => 42,
            'selected_language' => 'english',
        ]);

        $notFound = new NotFoundHttpException('The file does not exist in the selected language.');
        $images = $this->createMock(ImageService::class);
        $images->expects($this->once())
            ->method('getBookImage')
            ->willThrowException($notFound);

        $request = new GetBookImageRequest();

        $this->expectExceptionObject($notFound);
        (new ImageController($images))->getBookImage('missing.jpg', $request);
    }
}
