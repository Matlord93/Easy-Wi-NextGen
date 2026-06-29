<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MusicbotCustomerExperienceRedesignTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/musicbot/show.html.twig');
        self::assertIsString($template);
        $this->template = $template;
    }

    public function testDashboardQueueLibraryPlaylistWebradioYoutubeAndAutoDjUxAreRepresented(): void
    {
        foreach ([
            'mb-hero-player',
            'mb-waveform',
            'mb-live-badge',
            'data-live-region="now-playing"',
            'Live Queue',
            '<th style="font-size:11px;">Quelle</th>',
            '<th style="font-size:11px;">Künstler</th>',
            '<th style="font-size:11px;">Dauer</th>',
            '<th style="font-size:11px;">Hinzugefügt von</th>',
            'Drag',
            'Bibliothek',
            'Kartenansicht',
            'Tabellenansicht',
            'Radio-App',
            'YouTube Music',
            'Playlist',
            'Trigger',
            'Wiederholschutz',
        ] as $needle) {
            self::assertStringContainsString($needle, $this->template);
        }
    }

    public function testOperationsTabsCoverPluginsTeamspeakDiscordMonitoringBackupRolesSettingsAndMobile(): void
    {
        foreach ([
            'Marketplace',
            'Plugin-Logs',
            'TeamSpeak Live',
            'Discord-Unterstützung folgt.',
            'panel-monitoring',
            'PulseAudio',
            'panel-backup',
            'Migration vorbereiten',
            'Grafischer Rolleneditor',
            'mb-permission-matrix',
            'Wiedergabe',
            'API',
            '@media (max-width:760px)',
            'data-dashboard-status-cards',
            'mb-stat-card',
        ] as $needle) {
            self::assertStringContainsString($needle, $this->template);
        }
    }

    public function testLiveUpdatesUseEventSourceAndAvoidPermanentPolling(): void
    {
        self::assertStringContainsString('window.EventSource', $this->template);
        self::assertStringContainsString('data-live-field="progress"', $this->template);
        self::assertStringContainsString('var np = data.nowPlaying || data.now_playing || {};', $this->template);
        self::assertStringNotContainsString('data.currentTitle || (data.currentTrack', $this->template);
        self::assertStringNotContainsString('setInterval(', $this->template);
    }
}
