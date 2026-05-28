<?php

declare(strict_types=1);

namespace App\Tests\Module\PanelAdmin\UI\Controller;

use PHPUnit\Framework\TestCase;

final class AdminGdprPanelStaticTest extends TestCase
{
    public function testUiShowsActiveInactiveStatusAndNoCrontabInstructionInPrivacyArea(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../templates/admin/gdpr/index.html.twig');
        self::assertIsString($template);
        self::assertStringContainsString('admin_gdpr_background_status_active', $template);
        self::assertStringContainsString('admin_gdpr_background_status_inactive', $template);
        self::assertStringContainsString('admin_gdpr_background_panel_managed', $template);
        self::assertStringNotContainsString('crontab', strtolower($template));
        self::assertStringNotContainsString('app:gdpr:exports:process', $template);
    }

    public function testToggleAndManualRunRoutesArePresentAndSuperadminProtected(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../../../../src/Module/PanelAdmin/UI/Controller/Admin/AdminGdprController.php');
        self::assertIsString($controller);
        self::assertStringContainsString('admin_gdpr_background_jobs_toggle', $controller);
        self::assertStringContainsString('setPrivacyGdprJobsEnabled', $controller);
        self::assertStringContainsString('admin_gdpr_background_jobs_run_now', $controller);
        self::assertStringContainsString('UserType::Superadmin', $controller);
    }
}
