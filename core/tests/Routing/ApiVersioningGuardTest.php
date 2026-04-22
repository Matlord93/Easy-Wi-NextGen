<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

final class ApiVersioningGuardTest extends KernelTestCase
{
    public function testLegacyApiRoutesAreAllowlisted(): void
    {
        self::bootKernel();

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $routes = $router->getRouteCollection();

        $allowedPatterns = [
            '#^/api/admin/users$#',
            '#^/api/admin/shop/provision$#',
            '#^/api/admin/port-pools$#',
            '#^/api/port-blocks$#',
            '#^/api/admin/port-blocks$#',
            '#^/api/admin/instances$#',
            '#^/api/admin/instances/\\{id\\}$#',
            '#^/api/admin/instances/\\{id\\}/update-settings$#',
            '#^/api/admin/webspaces$#',
            '#^/api/admin/webspaces/\\{id\\}(?:/(?:resume|suspend))?$#',
            '#^/api/ts3/instances$#',
            '#^/api/ts3/instances/\\{id\\}/actions$#',
            '#^/api/auth/login$#',
            '#^/api/instances$#',
            '#^/api/instances/\\{id\\}/sftp-credentials(?:/reset)?$#',
            '#^/api/instances/\\{id\\}/addons(?:/health|/\\{addonId\\}(?:/(?:install|update|remove))?)?$#',
            '#^/api/instances/\\{id\\}/addons/(install|remove|update)$#',
            '#^/api/instances/\\{id\\}/backups(?:/mode|/health|/\\{backupId\\}(?:/restore|/download)?)?$#',
            '#^/api/instances/\\{id\\}/schedules(?:/\\{action\\})?$#',
            '#^/api/instances/\\{id\\}/console/(?:commands|logs|command|health)$#',
            '#^/api/instances/\\{id\\}/settings(?:/automation|/health)?$#',
            '#^/api/instances/\\{id\\}/reinstall(?:/options)?$#',
            '#^/api/instances/\\{id\\}/files(?:/.*)?$#',
            '#^/api/instances/\\{id\\}/status/fix$#',
            '#^/api/instances/\\{id\\}/power$#',
            '#^/api/instances/\\{id\\}/slots$#',
            '#^/api/instances/\\{id\\}/query(?:/health)?$#',
            '#^/api/instances/\\{id\\}/tasks(?:/logs|/health|/\\{taskId\\}/cancel)?$#',
            '#^/api/instances/\\{id\\}/access/(?:health|reset|reveal)$#',
            '#^/api/instances/\\{id\\}/configs(?:/health|/templates(?:/\\{targetId\\}(?:/create)?)?|/\\{configId\\}(?:/apply)?)?$#',
            '#^/api/customer/instances/\\{id\\}/configs(?:/\\{configId\\}(?:/generate-save|/apply)?)?$#',
            '#^/api/customer/instances/\\{id\\}/actions$#',
            '#^/api/customer/instances/\\{id\\}/query$#',
            '#^/api/customer/jobs/\\{jobId\\}(?:/logs|/cancel)?$#',
            '#^/api/backups(?:/\\{id\\}/schedule)?$#',
            '#^/api/backup-targets(?:/\\{id\\})?$#',
            '#^/api/databases(?:/\\{id\\}(?:/password)?)?$#',
            '#^/api/mailboxes(?:/\\{id\\}(?:/(quota|status|password))?)?$#',
            '#^/api/tickets(?:/\\{id\\}/(messages|status))?$#',
            '#^/api/dns/records(?:/\\{id\\})?$#',
            '#^/api/webspaces$#',
            '#^/api/agent/(?:status-batch|servers)$#',
            '#^/api/servers/status$#',
        ];

        $violations = [];

        foreach ($routes as $name => $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/api/') || str_starts_with($path, '/api/v1/')) {
                continue;
            }

            $isAllowed = false;
            foreach ($allowedPatterns as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                $violations[] = sprintf('%s -> %s', $name, $path);
            }
        }

        self::assertSame([], $violations, "Legacy API routes must be allowlisted:\n" . implode("\n", $violations));
    }
}
