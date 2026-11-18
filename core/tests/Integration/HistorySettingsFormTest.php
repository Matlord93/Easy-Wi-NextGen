<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminSettingsController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class HistorySettingsFormTest extends KernelTestCase
{
    public function testSettingsRequireAnAdminCsrfTokenAndPersistModeAndIndependentRetentionPeriods(): void
    {
        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $request = Request::create('/admin/settings', 'POST', ['tab' => 'logging', 'job_history_mode' => 'errors', 'metrics_retention_days' => '14', 'metrics_instance_retention_days' => '10', 'database_logging_routine_retention_days' => '3', 'database_logging_error_retention_days' => '7']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push($request);
        try {
            $controller = self::getContainer()->get(AdminSettingsController::class);
            self::assertSame(403, $controller->update($request)->getStatusCode());
            $admin = new User('admin-history@example.test', UserType::Admin);
            $admin->setPasswordHash('fixture-only');
            $em->persist($admin);
            $em->flush();
            $request->attributes->set('current_user', $admin);
            self::assertSame(403, $controller->update($request)->getStatusCode());
            $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('admin_settings_logging');
            $request->request->set('_token', $token->getValue());
            $savedResponse = $controller->update($request);
            self::assertSame(302, $savedResponse->getStatusCode());
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $savedResponse->headers->get('X-Audit-Hash'));
            $settings = self::getContainer()->get(AppSettingsServiceInterface::class)->getSettings();
            self::assertSame('errors', $settings['job_history_mode']);
            self::assertSame(14, $settings['metrics_retention_days']);
            self::assertSame(10, $settings['metrics_instance_retention_days']);
            self::assertSame(3, $settings['database_logging_routine_retention_days']);
            self::assertSame(7, $settings['database_logging_error_retention_days']);
            $request->request->set('job_history_mode', 'unknown');
            $invalid = $controller->update($request);
            self::assertSame(400, $invalid->getStatusCode());
            self::assertStringContainsString('name="job_history_mode"', $invalid->getContent());
            self::assertStringContainsString('name="_token"', $invalid->getContent());
            self::assertSame('errors', self::getContainer()->get(AppSettingsServiceInterface::class)->getSettings()['job_history_mode']);
        } finally {
            $stack->pop();
            self::ensureKernelShutdown();
            foreach (['SERVER', 'ENV'] as $index => $global) {
                if ($previous[$index] === null) { unset($GLOBALS['_'.$global]['DATABASE_URL']); }
                else { $GLOBALS['_'.$global]['DATABASE_URL'] = $previous[$index]; }
            }
        }
    }
}
