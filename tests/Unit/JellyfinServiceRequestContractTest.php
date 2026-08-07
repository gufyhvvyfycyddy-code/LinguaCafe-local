<?php

namespace Tests\Unit;

use App\Services\JellyfinService;
use PHPUnit\Framework\TestCase;

class JellyfinServiceRequestContractTest extends TestCase
{
    public function test_playback_and_subtitle_requests_use_the_session_user_and_exact_subtitle_path(): void
    {
        $calls = [];
        $service = $this->getMockBuilder(JellyfinService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $languages = new \ReflectionProperty(JellyfinService::class, 'jellyfinLanguageCodes');
        $languages->setAccessible(true);
        $languages->setValue($service, ['eng' => 'english']);

        $service->method('makeRequest')->willReturnCallback(
            function (string $method, string $url) use (&$calls): array {
                $calls[] = [$method, $url];

                return match (count($calls)) {
                    1 => [[
                        'Client' => 'Web',
                        'UserName' => 'Tester',
                        'UserId' => 'user-123',
                        'Id' => 'session-1',
                        'PlayState' => ['MediaSourceId' => 'source-1'],
                        'NowPlayingItem' => [
                            'MediaType' => 'Video',
                            'Id' => 'item-456',
                            'Name' => 'Movie',
                            'Type' => 'Movie',
                            'RunTimeTicks' => 100,
                        ],
                    ]],
                    2 => [
                        'MediaSources' => [[
                            'MediaStreams' => [[
                                'Type' => 'Subtitle',
                                'IsExternal' => true,
                                'Language' => 'eng',
                                'Index' => 2,
                            ]],
                        ]],
                    ],
                    3 => ['TrackEvents' => [['Text' => 'Hello']]],
                    default => throw new \RuntimeException('Unexpected Jellyfin request.'),
                };
            },
        );

        $sessions = $service->getJellyfinCurrentlyPlayedSubtitles();

        $this->assertSame([
            ['GET', '/Sessions'],
            ['GET', '/Items/item-456/PlaybackInfo?userId=user-123'],
            ['GET', '/Videos/item-456/source-1/Subtitles/2/0/Stream.js'],
        ], $calls);
        $this->assertCount(1, $sessions);
        $this->assertSame('user-123', $sessions[0]->userId);
        $this->assertSame('english', $sessions[0]->subtitles[0]->language);
        $this->assertSame([['Text' => 'Hello']], $sessions[0]->subtitles[0]->text);
    }

    public function test_playback_request_omits_optional_user_id_when_session_has_none(): void
    {
        $calls = [];
        $service = $this->getMockBuilder(JellyfinService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['makeRequest'])
            ->getMock();

        $languages = new \ReflectionProperty(JellyfinService::class, 'jellyfinLanguageCodes');
        $languages->setAccessible(true);
        $languages->setValue($service, ['eng' => 'english']);

        $service->method('makeRequest')->willReturnCallback(
            function (string $method, string $url) use (&$calls): array {
                $calls[] = [$method, $url];

                return match (count($calls)) {
                    1 => [[
                        'Client' => 'DLNA',
                        'UserName' => 'Guest',
                        'Id' => 'session-2',
                        'PlayState' => ['MediaSourceId' => 'source-2'],
                        'NowPlayingItem' => [
                            'MediaType' => 'Video',
                            'Id' => 'item-789',
                            'Name' => 'Guest Movie',
                            'Type' => 'Movie',
                            'RunTimeTicks' => 200,
                        ],
                    ]],
                    2 => [
                        'MediaSources' => [[
                            'MediaStreams' => [[
                                'Type' => 'Subtitle',
                                'IsExternal' => true,
                                'Language' => 'eng',
                                'Index' => 4,
                            ]],
                        ]],
                    ],
                    3 => ['TrackEvents' => [['Text' => 'Guest subtitle']]],
                    default => throw new \RuntimeException('Unexpected Jellyfin request.'),
                };
            },
        );

        $sessions = $service->getJellyfinCurrentlyPlayedSubtitles();

        $this->assertSame([
            ['GET', '/Sessions'],
            ['GET', '/Items/item-789/PlaybackInfo'],
            ['GET', '/Videos/item-789/source-2/Subtitles/4/0/Stream.js'],
        ], $calls);
        $this->assertNull($sessions[0]->userId);
    }
}
