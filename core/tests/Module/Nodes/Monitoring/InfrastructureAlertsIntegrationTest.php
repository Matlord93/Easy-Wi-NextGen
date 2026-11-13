<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Core\Domain\Entity\NotificationPreference;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\Monitoring\InfrastructureAlerts;
use App\Module\Nodes\Application\Monitoring\InfrastructureMonitoringSettings;
use App\Module\Nodes\Application\Monitoring\InfrastructureTarget;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InfrastructureAlertsIntegrationTest extends KernelTestCase
{
    public function testSustainedAlarmsRecoveryDuplicateDeliveryAndPreferences(): void
    {
        $previous = [];
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/infra-alert-test-no-config.json'] as $key => $value) {
            $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::ensureKernelShutdown();
        try {
            self::bootKernel();
            $c = self::getContainer();
            $em = $c->get(EntityManagerInterface::class);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $admin = new User('infra-admin@example.test', UserType::Admin);
            $owner = new User('infra-owner@example.test', UserType::Customer);
            $other = new User('infra-other@example.test', UserType::Customer);
            foreach ([$admin, $owner, $other] as $user) {
                $user->setPasswordHash(password_hash('synthetic-integration-only', PASSWORD_BCRYPT));
                $em->persist($user);
            }
            $em->flush();
            $settings = $c->get(InfrastructureMonitoringSettings::class);
            $settings->save(array_replace($settings->get(), ['hold_seconds' => 60, 'offline_seconds' => 120]));
            $target = new InfrastructureTarget('kvm', 'test-node', 'vm', 'test-vm', 'owner-1', str_repeat('Langer VM-Name ', 20), '/admin/kvm/nodes/test-node', $owner, '/customer/kvm/1');
            $alerts = $c->get(InfrastructureAlerts::class);
            $db = $em->getConnection();
            $at = time() - 600;
            $high = ['cpu_percent' => 95, 'memory_percent' => 10, 'disk_percent' => 10];
            $alerts->observe($target, $high, $at, true);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications'));
            $alerts->observe($target, $high, $at + 60, true);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications'));
            $alerts->observe($target, $high, $at + 60, true);
            $alerts->observe($target, $high, $at + 120, true);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications'));
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications WHERE recipient_id = ?', [$other->getId()]));
            self::assertLessThanOrEqual(160, mb_strlen($db->fetchOne('SELECT title FROM notifications LIMIT 1')));
            $alerts->observe($target, ['cpu_percent' => 20], $at + 180, true);
            self::assertSame(4, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications'));
            $preference = new NotificationPreference($owner, 'infrastructure', false);
            $em->persist($preference);
            $em->flush();
            $alerts->observe($target, [], $at + 240, false);
            $alerts->observe($target, [], $at + 360, false);
            self::assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications'));
            self::assertTrue($alerts->policy($target)['state']['offline']['active']);
            $alerts->observe($target, [], $at + 420, true);
            self::assertSame(6, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications'));
            self::assertFalse($alerts->policy($target)['state']['offline']['active']);
            // A per-VM override cannot bypass the global alert switch.
            $settings->save(array_replace($settings->get(), ['alerts_enabled' => false]));
            $alerts->configure($target, ['cpu_percent' => 90, 'memory_percent' => 90, 'disk_percent' => 90, 'alerts_enabled' => true]);
            $alerts->observe($target, $high, $at + 480, true);
            $alerts->observe($target, $high, $at + 540, true);
            self::assertSame(6, (int) $db->fetchOne('SELECT COUNT(*) FROM notifications'));
        } finally {
            self::ensureKernelShutdown();
            foreach ($previous as $key => [$server, $env]) {
                if (null === $server) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if (null === $env) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
            }
        }
    }
}
