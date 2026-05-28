<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\TestCase;

final class AdminUpdateCardContractTest extends TestCase
{
    public function testRollbackButtonIsHiddenWhenRollbackUnavailable(): void
    {
        $template = (string) file_get_contents(__DIR__.'/../../templates/admin/dashboard/_web_update_card.html.twig');
        self::assertStringContainsString('coreUpdate.rollbackAvailable and coreUpdate.backups is not empty', $template);
    }

    public function testManualUpdateButtonNotBoundToAutoEnabled(): void
    {
        $template = (string) file_get_contents(__DIR__.'/../../templates/admin/dashboard/_web_update_card.html.twig');
        self::assertStringContainsString('name="type" value="update"', $template);
        self::assertStringNotContainsString('disabled', substr($template, strpos($template, 'name="type" value="update"') - 300, 600));
    }

    public function testEnglishNewSchedulerTranslationsAreEnglish(): void
    {
        $translations = (string) file_get_contents(__DIR__.'/../../translations/portal.en.yaml');
        self::assertStringContainsString('admin_updates_internal_scheduler_section: "Internal automation"', $translations);
        self::assertStringContainsString('admin_updates_internal_scheduler_description: "Webinterface and agent updates are checked and executed regularly by the internal scheduler system."', $translations);
        self::assertStringNotContainsString('Interne Automatisierung', $translations);
    }

    public function testUpdateLogBlockIsCompactAndCollapsible(): void
    {
        $template = (string) file_get_contents(__DIR__.'/../../templates/admin/dashboard/_web_update_card.html.twig');
        self::assertStringContainsString('<details {% if isRunning %}open{% endif %}>', $template);
        self::assertStringContainsString('max-height:200px;overflow:auto;overflow-x:auto', $template);
        self::assertStringContainsString('white-space:pre;', $template);
        self::assertStringContainsString("t('update_logs_empty', page_locale())", $template);
    }

    public function testUpdateCardUsesTailLimitFifty(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminUpdateController.php');
        self::assertStringContainsString("tailLog(\$latestJob['logPath'] ?? null, 50)", $controller);
        self::assertStringNotContainsString("tailLog(\$latestJob['logPath'] ?? null, 200)", $controller);
    }

    public function testUpdateCardPollsOnlyWhenJobIsRunning(): void
    {
        $template = (string) file_get_contents(__DIR__.'/../../templates/admin/dashboard/_web_update_card.html.twig');
        self::assertStringContainsString('hx-trigger="every 3s"', $template);
        self::assertStringContainsString('{% if isRunning %} hx-get="/admin/updates/webinterface/check"', $template);
    }

    public function testUpdateControllerSetsNoStoreCacheHeaders(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminUpdateController.php');
        self::assertStringContainsString("Cache-Control', 'no-store, no-cache, must-revalidate", $controller);
    }

    public function testUpdateControllerProvidesHtmxResponseHelpers(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminUpdateController.php');
        self::assertStringContainsString('private function htmxRefresh(): Response', $controller);
        self::assertStringContainsString("'HX-Refresh' => 'true'", $controller);
        self::assertStringContainsString('private function htmxRedirect(string $url): Response', $controller);
        self::assertStringContainsString("'HX-Redirect' => $url", $controller);
    }
}
