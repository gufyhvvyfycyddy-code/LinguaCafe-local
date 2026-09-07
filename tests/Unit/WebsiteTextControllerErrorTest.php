<?php

namespace Tests\Unit;

use App\Http\Controllers\ImportController;
use App\Http\Requests\Import\GetWebsiteTextRequest;
use App\Services\ImportService;
use App\Services\TempFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WebsiteTextControllerErrorTest extends TestCase
{
    public function test_lookup_failure_returns_safe_service_unavailable_json(): void
    {
        Log::spy();

        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('getWebsiteText')
            ->with('https://example.com/article')
            ->willThrowException(new \RuntimeException('crawler failed at C:\\private\\worker.py with secret details'));

        $controller = new ImportController(
            $imports,
            $this->createStub(TempFileService::class),
        );
        $request = new GetWebsiteTextRequest();
        $request->initialize([], [
            'url' => 'https://example.com/article',
        ]);

        $response = $controller->getWebsiteText($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('WEBSITE_TEXT_SERVICE_UNAVAILABLE', $response->getData(true)['error']['code'] ?? null);
        $this->assertSame('暂时无法获取网页内容，请稍后重试。', $response->getData(true)['error']['message'] ?? null);
        $this->assertStringNotContainsString('private', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('website_text_lookup_failed', [
                'exception' => \RuntimeException::class,
            ]);
    }

    public function test_success_payload_remains_unchanged(): void
    {
        $text = 'Example article text.';
        $imports = $this->createMock(ImportService::class);
        $imports->expects($this->once())
            ->method('getWebsiteText')
            ->willReturn($text);

        $controller = new ImportController(
            $imports,
            $this->createStub(TempFileService::class),
        );
        $request = new GetWebsiteTextRequest();
        $request->initialize([], [
            'url' => 'https://example.com/article',
        ]);

        $response = $controller->getWebsiteText($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($text, $response->getData(true));
    }
}
