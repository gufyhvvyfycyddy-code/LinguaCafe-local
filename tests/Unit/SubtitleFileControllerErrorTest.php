<?php

namespace Tests\Unit;

use App\Http\Controllers\ImportController;
use App\Http\Requests\Import\GetSubtitleFileContentRequest;
use App\Services\ImportService;
use App\Services\TempFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SubtitleFileControllerErrorTest extends TestCase
{
    public function test_temp_store_failure_does_not_attempt_cleanup_or_expose_exception(): void
    {
        Auth::shouldReceive('user')->andReturn((object) ['id' => 42]);
        Log::spy();

        $imports = $this->createStub(ImportService::class);
        $tempFiles = $this->createMock(TempFileService::class);
        $tempFiles->expects($this->once())
            ->method('moveFileToTempFolder')
            ->willThrowException(new \RuntimeException('failed at C:\\private\\temp with secret details'));
        $tempFiles->expects($this->never())->method('deleteTempFile');

        $response = (new ImportController($imports, $tempFiles))
            ->getSubtitleFileContent($this->requestWithSubtitle());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('SUBTITLE_FILE_READ_FAILED', $response->getData(true)['error']['code'] ?? null);
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->with(
            'subtitle_file_read_failed',
            ['stage' => 'store', 'exception' => \RuntimeException::class],
        );
    }

    public function test_parser_failure_cleans_the_created_temp_file_and_returns_safe_json(): void
    {
        Auth::shouldReceive('user')->andReturn((object) ['id' => 42]);
        Log::spy();

        $tempFiles = $this->createMock(TempFileService::class);
        $tempFiles->expects($this->once())
            ->method('moveFileToTempFolder')
            ->willReturn('42_sample.srt');
        $tempFiles->expects($this->once())
            ->method('deleteTempFile')
            ->with('42_sample.srt')
            ->willReturn(true);

        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('getSubtitleFileContent')
            ->willThrowException(new \RuntimeException('tokenizer parser internals'));

        $response = (new ImportController($imports, $tempFiles))
            ->getSubtitleFileContent($this->requestWithSubtitle());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('SUBTITLE_FILE_READ_FAILED', $response->getData(true)['error']['code'] ?? null);
        $this->assertStringNotContainsString('internals', $response->getContent());
        Log::shouldHaveReceived('warning')->once()->with(
            'subtitle_file_read_failed',
            ['stage' => 'parse', 'exception' => \RuntimeException::class],
        );
    }

    public function test_success_still_cleans_temp_file_and_preserves_payload(): void
    {
        Auth::shouldReceive('user')->andReturn((object) ['id' => 42]);

        $tempFiles = $this->createMock(TempFileService::class);
        $tempFiles->expects($this->once())
            ->method('moveFileToTempFolder')
            ->willReturn('42_sample.srt');
        $tempFiles->expects($this->once())
            ->method('deleteTempFile')
            ->with('42_sample.srt')
            ->willReturn(true);

        $payload = [['start' => 0, 'end' => 1, 'text' => 'Example']];
        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('getSubtitleFileContent')
            ->willReturn($payload);

        $response = (new ImportController($imports, $tempFiles))
            ->getSubtitleFileContent($this->requestWithSubtitle());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($payload, $response->getData(true));
    }

    private function requestWithSubtitle(): GetSubtitleFileContentRequest
    {
        $request = new GetSubtitleFileContentRequest();
        $request->files->set(
            'subtitleFile',
            UploadedFile::fake()->create('sample.srt', 1, 'application/x-subrip'),
        );

        return $request;
    }
}
