<?php

namespace Tests\Unit;

use App\Http\Controllers\ImportController;
use App\Http\Requests\Import\GetYoutubeSubtitlesRequest;
use App\Services\ImportService;
use App\Services\TempFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class YoutubeSubtitleControllerErrorTest extends TestCase
{
    public function test_lookup_failure_returns_safe_service_unavailable_json(): void
    {
        Log::spy();

        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('getYoutubeSubtitles')
            ->with('https://www.youtube.com/watch?v=example')
            ->willThrowException(new \RuntimeException('tokenizer failed at C:\\private\\worker.py with secret details'));

        $controller = new ImportController(
            $imports,
            $this->createStub(TempFileService::class),
        );
        $request = new GetYoutubeSubtitlesRequest();
        $request->initialize([], [
            'url' => 'https://www.youtube.com/watch?v=example',
        ]);

        $response = $controller->getYoutubeSubtitles($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('YOUTUBE_SUBTITLE_SERVICE_UNAVAILABLE', $response->getData(true)['error']['code'] ?? null);
        $this->assertSame('暂时无法获取 YouTube 字幕，请稍后重试。', $response->getData(true)['error']['message'] ?? null);
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('youtube_subtitle_lookup_failed', [
                'exception' => \RuntimeException::class,
            ]);
    }

    public function test_success_payload_remains_unchanged(): void
    {
        $subtitles = [
            ['language' => 'English', 'text' => 'Example subtitle'],
        ];
        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('getYoutubeSubtitles')
            ->willReturn($subtitles);

        $controller = new ImportController(
            $imports,
            $this->createStub(TempFileService::class),
        );
        $request = new GetYoutubeSubtitlesRequest();
        $request->initialize([], [
            'url' => 'https://www.youtube.com/watch?v=example',
        ]);

        $response = $controller->getYoutubeSubtitles($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($subtitles, $response->getData(true));
    }
}
