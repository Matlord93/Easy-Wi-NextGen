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
        self::assertStringContainsString('hx-trigger="load, every 2s"', $template);
        self::assertStringContainsString('{% if isRunning %} hx-post="/admin/updates/job/{{ coreUpdate.latestJob.id }}/tick"', $template);
        self::assertStringContainsString('hx-vals=', $template);
        self::assertStringContainsString('coreUpdate.csrf.tick', $template);
    }


    public function testTickEndpointIsCsrfProtectedAndUsesProcessor(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminUpdateController.php');
        self::assertStringContainsString("new CsrfToken('admin_update_tick_' . $id", $controller);
        self::assertStringContainsString('$this->csrfTokenManager->isTokenValid($token)', $controller);
        self::assertStringContainsString('$this->tickProcessor->tick($id)', $controller);
    }

    public function testCreateJobDoesNotTriggerRunner(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../src/Module/PanelAdmin/UI/Controller/Admin/AdminUpdateController.php');
        $createStart = strpos($controller, 'public function createJob');
        $tickStart = strpos($controller, 'public function tickJob');
        self::assertIsInt($createStart);
        self::assertIsInt($tickStart);
        $createJobBody = substr($controller, $createStart, $tickStart - $createStart);
        self::assertStringNotContainsString('triggerRunner', $createJobBody);
        self::assertStringNotContainsString('markJobFailedToStart', $createJobBody);
    }

    public function testNoRunnerRequirementTextIsRenderedInUpdateTranslations(): void
    {
        $translations = (string) file_get_contents(__DIR__.'/../../translations/portal.de.yaml')
            . (string) file_get_contents(__DIR__.'/../../translations/portal.en.yaml');
        self::assertStringNotContainsString('easywi-core-runner', $translations);
        self::assertStringNotContainsString('APP_CORE_UPDATE_RUNNER', $translations);
        self::assertStringContainsString('Panel-Updates werden ohne Runner verarbeitet', $translations);
    }

    public function testPanelTickProcessorUsesPerJobFlockAndWorkflowSteps(): void
    {
        $processor = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Application/PanelUpdateTickProcessor.php');
        self::assertStringContainsString("'/' . \$jobId . '.lock'", $processor);
        self::assertStringContainsString('flock($handle, LOCK_EX | LOCK_NB)', $processor);
        self::assertStringContainsString("'update' => ['apply_update']", $processor);
        self::assertStringContainsString("'migrate' => ['apply_migrations']", $processor);
        self::assertStringContainsString("'both' => ['apply_update', 'apply_migrations']", $processor);
        self::assertStringContainsString('legacySynchronousSteps', $processor);
        self::assertStringContainsString('set_time_limit(self::TICK_TIME_LIMIT_SECONDS)', $processor);
    }

    public function testPollingStopsForSuccessAndFailedJobs(): void
    {
        $template = (string) file_get_contents(__DIR__.'/../../templates/admin/dashboard/_web_update_card.html.twig');
        self::assertStringContainsString("coreUpdate.latestJob.status in ['pending', 'running']", $template);
        self::assertStringContainsString('{% if isRunning %}', $template);
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
        self::assertStringContainsString('\'HX-Redirect\' => $url', $controller);
    }
}
