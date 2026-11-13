<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore;
use App\Module\Nodes\Application\Monitoring\InfrastructureTargets;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class InfrastructureMonitoringHttpTest extends AbstractWebTestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public function testCustomerHistoryAndExportAreScopedToCurrentAssignment(): void
    {
        $client = self::createClient();
        $customer = $this->loginAsRole($client, 'customer');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $node = new Agent('monitor-http-'.bin2hex(random_bytes(4)), ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);
        $customer = $em->find(User::class, $customer->getId());
        $other = $this->createUser('monitor-other@example.test', UserType::Customer);
        $own = new KvmGuestAssignment($node, self::UUID, $customer);
        $foreign = new KvmGuestAssignment($node, '22222222-2222-4222-8222-222222222222', $other);
        // Simulate a previously established assignment, not an observation predating ownership.
        (new \ReflectionProperty(KvmGuestAssignment::class, 'assignedAt'))->setValue($own, new \DateTimeImmutable('-1 hour'));
        foreach ([$node, $own, $foreign] as $value) {
            $em->persist($value);
        } $em->flush();
        $target = self::getContainer()->get(InfrastructureTargets::class)->kvm($node, self::UUID);
        self::getContainer()->get(InfrastructureHistoryStore::class)->record($target, ['cpu_percent' => 35, 'observed_at' => gmdate(DATE_ATOM, time() - 2), 'load_source' => 'agent_heartbeat']);
        $url = '/customer/monitoring/kvm/'.$own->getId();
        $client->request('GET', $url.'?format=json&hours=1');
        self::assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $result['points']);
        self::assertSame(35, $result['points'][0]['metrics']['cpu_percent']['average']);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $client->request('GET', $url.'?format=csv&hours=1');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cpu_percent,35,35,35,agent_heartbeat', $client->getResponse()->getContent());
        $client->request('GET', '/customer/monitoring/kvm/'.$foreign->getId().'?format=json');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $url.'?format=json&from=2026-13-01T00:00:00Z&to=2026-13-02T00:00:00Z');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/admin/monitoring');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testAdminSettingsAndPerVmPolicyRequireCsrf(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $client->request('GET', '/admin/monitoring');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/admin/monitoring', ['action' => 'settings']);
        self::assertResponseStatusCodeSame(403);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $node = new Agent('monitor-policy-'.bin2hex(random_bytes(4)), ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);
        $em->persist($node);
        $em->flush();
        $target = self::getContainer()->get(InfrastructureTargets::class)->kvm($node, self::UUID);
        $url = '/admin/monitoring/kvm/'.$node->getId().'/vm/'.self::UUID;
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $client->request('POST', $url, ['cpu_percent' => '80']);
        self::assertResponseStatusCodeSame(403);
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('infrastructure-policy-'.$target->key)->getValue();
        $client->request('POST', $url, ['_token' => $token, 'cpu_percent' => '80', 'memory_percent' => '85', 'disk_percent' => '90', 'alerts_enabled' => '1']);
        self::assertResponseStatusCodeSame(303);
    }
}
