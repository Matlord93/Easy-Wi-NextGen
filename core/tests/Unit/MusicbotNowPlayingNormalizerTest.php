<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotRuntimeStatusNormalizer;
use PHPUnit\Framework\TestCase;

final class MusicbotNowPlayingNormalizerTest extends TestCase
{
    private MusicbotRuntimeStatusNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MusicbotRuntimeStatusNormalizer();
    }

    public function testWebradioStatusIsShownWhenCurrentTrackIsNull(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback' => ['state' => 'playing', 'current_track' => null],
            'playback_status' => [
                'playback_state' => 'playing',
                'current_source' => 'radio',
                'current_title' => 'technobase',
                'stream_url' => 'https://listen.technobase.fm/dsl.pls',
            ],
        ], ['title' => 'ssstik.io_1773960380106', 'source_type' => 'upload']);

        self::assertSame('technobase', $now['title']);
        self::assertSame('radio', $now['source_type']);
        self::assertSame('Webradio', $now['source_label']);
        self::assertSame('https://listen.technobase.fm/dsl.pls', $now['url']);
        self::assertTrue($now['is_live']);
    }

    public function testWebradioIsShownWhenQueueIsEmpty(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback_status' => [
                'playback_state' => 'playing',
                'source_type' => 'radio',
                'station_name' => 'Technobase.FM',
            ],
        ], null);

        self::assertSame('Technobase.FM', $now['title']);
        self::assertSame('radio', $now['source_type']);
    }

    public function testOldUploadQueueTrackIsNotShownForRadio(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback_status' => [
                'playback_state' => 'playing',
                'source_type' => 'radio',
                'current_title' => 'technobase',
            ],
        ], ['title' => 'ssstik.io_1773960380106', 'source_type' => 'upload']);

        self::assertSame('technobase', $now['title']);
        self::assertSame('radio', $now['source_type']);
    }

    public function testRuntimeRadioWithStaleUploadDatabaseAndTechnobaseQueueShowsWebradio(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback' => ['state' => 'playing', 'current_track' => null],
            'playback_status' => [
                'source_type' => 'radio',
            ],
            'audio_pipeline' => [
                'source_type' => 'radio',
                'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
            ],
        ], [
            'title' => 'technobase',
            'source_type' => 'webradio',
            'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
            'track' => [
                'title' => 'ytdown.com_youtube_pippi-langstrumpf-remix',
                'source_type' => 'upload',
            ],
        ]);

        self::assertSame('technobase', $now['title']);
        self::assertSame('radio', $now['source_type']);
        self::assertSame('Webradio', $now['source_label']);
        self::assertTrue($now['is_live']);
        self::assertSame('https://listen.technobase.fm/tunein-mp3-m3u', $now['url']);
        self::assertStringNotContainsString('pippi', $now['title']);
    }

    public function testTopLevelRuntimeRadioWithStaleCurrentTrackUsesRadioQueueTitle(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'source_type' => 'radio',
            'playback' => [
                'state' => 'playing',
                'current_track' => [
                    'title' => 'ssstik.io_1773960380106',
                    'source_type' => 'upload',
                ],
            ],
            'playback_status' => [
                'current_title' => '',
            ],
            'audio_pipeline' => [
                'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
            ],
        ], [
            'title' => 'technobase',
            'source_type' => 'webradio',
            'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
        ]);

        self::assertSame('technobase', $now['title']);
        self::assertSame('radio', $now['source_type']);
        self::assertSame('Webradio', $now['source_label']);
        self::assertTrue($now['is_live']);
        self::assertStringNotContainsString('ssstik.io_1773960380106', $now['title']);
    }

    public function testRuntimeRadioUsesCurrentTrackTitleBeforeStalePlaybackStatusTitle(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'source_type' => 'radio',
            'playback' => [
                'state' => 'playing',
                'current_track' => [
                    'id' => 6,
                    'title' => 'technobase',
                    'duration_seconds' => 0,
                    'source' => [
                        'type' => 'webradio',
                    ],
                ],
            ],
            'playback_status' => [
                'playback_state' => 'playing',
                'current_title' => 'ssstik.io_1773960380106',
            ],
        ]);
        $response = [
            'nowPlaying' => $now,
            'now_playing' => $now,
        ];

        self::assertSame('technobase', $response['nowPlaying']['title']);
        self::assertSame('technobase', $response['now_playing']['title']);
        self::assertSame('radio', $response['nowPlaying']['source_type']);
        self::assertSame('radio', $response['now_playing']['source_type']);
    }

    public function testTopLevelRuntimeRadioWithNullCurrentTrackUsesRadioQueueTitle(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'source_type' => 'radio',
            'playback' => [
                'state' => 'playing',
                'current_track' => null,
            ],
            'playback_status' => [
                'current_title' => '',
            ],
            'audio_pipeline' => [
                'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
            ],
        ], [
            'title' => 'technobase',
            'source_type' => 'webradio',
            'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
        ]);

        self::assertSame('technobase', $now['title']);
        self::assertSame('radio', $now['source_type']);
        self::assertSame('Webradio', $now['source_label']);
    }

    public function testRuntimeRadioWithEmptyQueueShowsHostnameInsteadOfStaleUpload(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback' => ['state' => 'playing', 'current_track' => null],
            'playback_status' => [
                'source_type' => 'radio',
            ],
            'audio_pipeline' => [
                'source_type' => 'radio',
                'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
            ],
        ], [
            'title' => 'ytdown.com_youtube_pippi-langstrumpf-remix',
            'source_type' => 'upload',
        ]);

        self::assertSame('Technobase.FM', $now['title']);
        self::assertSame('radio', $now['source_type']);
        self::assertSame('Webradio', $now['source_label']);
        self::assertTrue($now['is_live']);
        self::assertStringNotContainsString('pippi', $now['title']);
    }

    public function testUploadCurrentTrackIsShownOnlyWhenRuntimeReportsUpload(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback' => ['state' => 'playing', 'current_track' => ['title' => 'Upload Song', 'artist' => 'Local', 'source_type' => 'upload']],
        ]);

        self::assertSame('Upload Song', $now['title']);
        self::assertSame('Upload', $now['source_label']);
    }

    public function testStaleUploadIsIgnoredUntilRuntimeReportsUploadOrLocalFile(): void
    {
        $radio = $this->normalizer->buildNowPlaying([
            'playback' => ['state' => 'playing', 'current_track' => null],
            'playback_status' => ['source_type' => 'radio'],
            'audio_pipeline' => ['source_type' => 'radio', 'url' => 'https://listen.technobase.fm/tunein-mp3-m3u'],
        ], ['title' => 'ytdown.com_youtube_pippi-langstrumpf-remix', 'source_type' => 'upload']);
        $upload = $this->normalizer->buildNowPlaying([
            'playback' => [
                'state' => 'playing',
                'current_track' => [
                    'title' => 'ytdown.com_youtube_pippi-langstrumpf-remix',
                    'source_type' => 'upload',
                ],
            ],
        ], ['title' => 'technobase', 'source_type' => 'webradio']);

        self::assertSame('Technobase.FM', $radio['title']);
        self::assertSame('radio', $radio['source_type']);
        self::assertSame('ytdown.com_youtube_pippi-langstrumpf-remix', $upload['title']);
        self::assertSame('upload', $upload['source_type']);
    }

    public function testYoutubeCurrentTrackIncludesArtistAndThumbnail(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback' => ['state' => 'playing', 'current_track' => ['title' => 'Video', 'artist' => 'Channel', 'source_type' => 'youtube', 'thumbnail' => 'https://img.example/video.jpg']],
        ]);

        self::assertSame('Video', $now['title']);
        self::assertSame('Channel', $now['artist']);
        self::assertSame('YouTube', $now['source_label']);
        self::assertSame('https://img.example/video.jpg', $now['thumbnail']);
    }

    public function testStoppedPlaybackDoesNotExposeOldQueueItem(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback_status' => ['playback_state' => 'stopped'],
        ], ['title' => 'Old queue title', 'source_type' => 'upload']);

        self::assertSame('', $now['title']);
        self::assertSame('', $now['source_type']);
    }

    public function testQueueTitleIsOnlyFallbackWhenRuntimeHasNoCurrentTitle(): void
    {
        $runtimeTitle = $this->normalizer->buildNowPlaying([
            'playback_status' => ['playback_state' => 'playing', 'current_title' => 'Runtime Title', 'current_source' => 'youtube'],
        ], ['title' => 'Queue Title', 'source_type' => 'upload']);
        $queueTitle = $this->normalizer->buildNowPlaying([
            'playback_status' => ['playback_state' => 'playing'],
        ], ['title' => 'Queue Title', 'source_type' => 'upload']);

        self::assertSame('Runtime Title', $runtimeTitle['title']);
        self::assertSame('Queue Title', $queueTitle['title']);
    }

    public function testRuntimeRadioIgnoresCurrentTitleWhenItMatchesStaleUploadTrack(): void
    {
        $now = $this->normalizer->buildNowPlaying([
            'playback' => [
                'state' => 'playing',
                'current_track' => [
                    'title' => 'ssstik.io_1773960380106',
                    'source_type' => 'upload',
                ],
            ],
            'playback_status' => [
                'playback_state' => 'playing',
                'current_title' => 'ssstik.io_1773960380106',
                'current_source' => 'radio',
            ],
            'audio_pipeline' => [
                'url' => 'https://listen.technobase.fm/tunein-mp3-m3u',
            ],
        ], ['title' => 'technobase', 'source_type' => 'webradio']);

        self::assertSame('technobase', $now['title']);
        self::assertSame('radio', $now['source_type']);
        self::assertSame('Webradio', $now['source_label']);
        self::assertStringNotContainsString('ssstik.io_1773960380106', $now['title']);
    }

    public function testDetailTemplateAndLiveUpdatesOnlyReadNowPlaying(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/musicbot/show.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString('var np = data.nowPlaying || data.now_playing || {};', $template);
        self::assertStringNotContainsString('currentTitle', $template);
        self::assertStringNotContainsString('currentTrack.title', $template);
        self::assertStringNotContainsString('instance.currentTrack', $template);
    }

    public function testCustomerListUsesNowPlayingLikeDetailView(): void
    {
        $controller = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        $index = file_get_contents(__DIR__.'/../../templates/customer/musicbot/index.html.twig');

        self::assertIsString($controller);
        self::assertIsString($index);
        self::assertStringContainsString("'nowPlaying' => $", $controller);
        self::assertStringContainsString("->buildNowPlaying", $controller);
        self::assertStringContainsString('row.nowPlaying.title', $index);
        self::assertStringNotContainsString('row.currentTrack', $index);
    }
}
