<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\Mail\MailBackendContext;
use PHPUnit\Framework\TestCase;

final class MailBackendContextTest extends TestCase
{
    public function testLifecycleEnableCreateDisableBlocksFurtherOperations(): void
    {
        $settingsState = [
            AppSettingsService::KEY_MAIL_ENABLED => true,
            AppSettingsService::KEY_MAIL_BACKEND => 'local',
        ];
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getSettings')->willReturnCallback(static function () use (&$settingsState): array {
            return $settingsState;
        });
        $context = new MailBackendContext($settings);

        self::assertTrue($context->operationsAllowed());

        $settingsState[AppSettingsService::KEY_MAIL_ENABLED] = false;
        self::assertFalse($context->operationsAllowed());
        self::assertSame('MAIL_BACKEND_DISABLED', $context->blockedResponsePayload('mailbox')['error_code']);
    }

    public function testBlockedResponseWhenBackendNone(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getSettings')->willReturn([
            AppSettingsService::KEY_MAIL_ENABLED => true,
            AppSettingsService::KEY_MAIL_BACKEND => 'none',
        ]);

        $context = new MailBackendContext($settings);
        $payload = $context->blockedResponsePayload('mailbox');

        self::assertNotNull($payload);
        self::assertSame('MAIL_BACKEND_DISABLED', $payload['error_code']);
        self::assertSame('none', $payload['mail_backend']);
    }


    public function testInvalidBackendFallsBackToLocal(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getSettings')->willReturn([
            AppSettingsService::KEY_MAIL_ENABLED => true,
            AppSettingsService::KEY_MAIL_BACKEND => 'unknown-backend',
        ]);

        $context = new MailBackendContext($settings);

        self::assertSame('local', $context->backend());
        self::assertTrue($context->operationsAllowed());
    }

    public function testOperationsAllowedWhenEnabledAndLocal(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getSettings')->willReturn([
            AppSettingsService::KEY_MAIL_ENABLED => true,
            AppSettingsService::KEY_MAIL_BACKEND => 'local',
        ]);

        $context = new MailBackendContext($settings);

        self::assertTrue($context->operationsAllowed());
        self::assertNull($context->blockedResponsePayload('mailbox'));
    }
}
