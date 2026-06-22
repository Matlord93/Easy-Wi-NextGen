<?php

declare(strict_types=1);

namespace App\Tests\Application;

use PHPUnit\Framework\TestCase;

final class MusicbotTeamspeakBackendUiTest extends TestCase
{
    public function testAdminTemplateShowsInstallRegisterButtonAndNoDownloadPromise(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/admin/musicbot/teamspeak_backend.html.twig');
        self::assertIsString($template);
        self::assertStringContainsString('Client Backend installieren/registrieren', $template);
        self::assertStringContainsString('Offiziellen TeamSpeak Client installieren', $template);
        self::assertStringContainsString('Lizenz-/Nutzungsbestätigung akzeptiert', $template);
        self::assertStringContainsString('lädt keine proprietären TeamSpeak-Dateien herunter', $template);
        self::assertStringContainsString('Installiert keine proprietären Dateien aus dem Internet', $template);
    }

    public function testCustomerTemplateShowsAdminMissingBackendMessage(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/musicbot/show.html.twig');
        self::assertIsString($template);
        self::assertStringContainsString('TeamSpeak Client Backend ist noch nicht vom Administrator eingerichtet.', $template);
    }
}
