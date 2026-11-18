<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Agent;
use Doctrine\ORM\EntityManagerInterface;

final class SftpHostTrustTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testOnlyAdminWithCsrfCanPinHostKey(): void
    {
        $instance = $this->seedInstance();
        $id = $instance->getNode()->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAsRole($client, 'admin');
        $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', 'trusted-key', true)), '=');
        $url = '/admin/nodes/'.$id.'/sftp-host-key';
        $client->request('POST', $url, ['fingerprint' => $fingerprint, '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $token = self::getContainer()->get(\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface::class)->getToken('node-sftp-host-key-'.$id)->getValue();
        $client->request('POST', $url, ['fingerprint' => 'not-a-fingerprint', '_token' => $token]);
        self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, ['fingerprint' => $fingerprint, '_token' => $token]);
        self::assertResponseStatusCodeSame(303);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($fingerprint, $em->find(Agent::class, $id)->getSftpHostFingerprint());
        $client->request('GET', '/logout');
        $this->loginAsRole($client, 'customer');
        $client->request('POST', $url, ['fingerprint' => '', '_token' => $token]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($fingerprint, self::getContainer()->get(EntityManagerInterface::class)->find(Agent::class, $id)->getSftpHostFingerprint());
    }
}
