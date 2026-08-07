<?php

namespace Tests\Unit;

use App\Http\Controllers\ImportController;
use App\Http\Requests\Import\ImportRequest;
use App\Services\ImportService;
use App\Services\TempFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ImportControllerErrorTest extends TestCase
{
    public function test_ebook_temp_store_failure_returns_safe_json_without_cleanup(): void
    {
        $this->mockUser();
        Log::spy();

        $imports = $this->createStub(ImportService::class);
        $tempFiles = $this->createMock(TempFileService::class);
        $tempFiles->expects($this->once())
            ->method('moveFileToTempFolder')
            ->willThrowException(new \RuntimeException('failed at C:\\private\\temp with secret details'));
        $tempFiles->expects($this->never())->method('deleteTempFile');

        $response = (new ImportController($imports, $tempFiles))
            ->import($this->ebookRequest());

        $this->assertSafeImportFailure($response);
        Log::shouldHaveReceived('warning')->once()->with(
            'content_import_failed',
            ['stage' => 'store', 'exception' => \RuntimeException::class],
        );
    }

    public function test_ebook_import_failure_cleans_temp_file_and_hides_exception(): void
    {
        $this->mockUser();
        Log::spy();

        $tempFiles = $this->createMock(TempFileService::class);
        $tempFiles->expects($this->once())
            ->method('moveFileToTempFolder')
            ->willReturn('42_book.epub');
        $tempFiles->expects($this->once())
            ->method('deleteTempFile')
            ->with('42_book.epub')
            ->willReturn(true);

        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('importBook')
            ->willThrowException(new \RuntimeException('parser failed at C:\\private\\book.epub'));

        $response = (new ImportController($imports, $tempFiles))
            ->import($this->ebookRequest());

        $this->assertSafeImportFailure($response);
        Log::shouldHaveReceived('warning')->once()->with(
            'content_import_failed',
            ['stage' => 'process', 'exception' => \RuntimeException::class],
        );
    }

    public function test_text_import_failure_returns_safe_json_without_temp_cleanup(): void
    {
        $this->mockUser();
        Log::spy();

        $tempFiles = $this->createMock(TempFileService::class);
        $tempFiles->expects($this->never())->method('moveFileToTempFolder');
        $tempFiles->expects($this->never())->method('deleteTempFile');

        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('importText')
            ->willThrowException(new \RuntimeException('tokenizer secret internals'));

        $response = (new ImportController($imports, $tempFiles))
            ->import($this->textRequest());

        $this->assertSafeImportFailure($response);
        Log::shouldHaveReceived('warning')->once()->with(
            'content_import_failed',
            ['stage' => 'process', 'exception' => \RuntimeException::class],
        );
    }

    public function test_cleanup_failure_after_success_does_not_hide_import_result(): void
    {
        $this->mockUser();
        Log::spy();

        $tempFiles = $this->createMock(TempFileService::class);
        $tempFiles->expects($this->once())
            ->method('moveFileToTempFolder')
            ->willReturn('42_book.epub');
        $tempFiles->expects($this->once())
            ->method('deleteTempFile')
            ->with('42_book.epub')
            ->willThrowException(new \RuntimeException('cleanup secret internals'));

        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('importBook')
            ->willReturn('basic_fallback');

        $response = (new ImportController($imports, $tempFiles))
            ->import($this->ebookRequest());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('导入成功。', $response->getData(true)['message'] ?? null);
        $this->assertSame('basic_fallback', $response->getData(true)['processing_mode'] ?? null);
        Log::shouldHaveReceived('warning')->once()->with(
            'content_import_temp_cleanup_failed',
            ['exception' => \RuntimeException::class],
        );
    }

    private function assertSafeImportFailure(mixed $response): void
    {
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('CONTENT_IMPORT_FAILED', $response->getData(true)['error']['code'] ?? null);
        $this->assertSame('导入失败，请稍后重试。', $response->getData(true)['error']['message'] ?? null);
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    private function mockUser(): void
    {
        Auth::shouldReceive('user')
            ->twice()
            ->andReturn((object) [
                'id' => 42,
                'uuid' => '00000000-0000-0000-0000-000000000042',
            ]);
    }

    private function ebookRequest(): ImportRequest
    {
        $request = $this->baseRequest('e-book');
        $request->files->set(
            'importFile',
            UploadedFile::fake()->create('book.epub', 1, 'application/epub+zip'),
        );

        return $request;
    }

    private function textRequest(): ImportRequest
    {
        $request = $this->baseRequest('plain-text');
        $request->request->set('importText', 'Example text.');

        return $request;
    }

    private function baseRequest(string $importType): ImportRequest
    {
        $request = new ImportRequest();
        $request->initialize([], [
            'importType' => $importType,
            'textProcessingMethod' => 'default',
            'eBookChapterSortMethod' => 'index',
            'bookId' => null,
            'bookName' => 'Example Book',
            'chapterName' => 'Example Chapter',
            'maximumCharactersPerChapter' => 5000,
        ]);

        return $request;
    }
}
