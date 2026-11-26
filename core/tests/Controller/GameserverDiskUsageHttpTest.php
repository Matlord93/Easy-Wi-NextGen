<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AgentSignatureVerifier;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\Scheduler\CentralSchedulerRunner;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Repository\JobRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithEnvironmentVariable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class GameserverDiskUsageHttpTest extends AbstractWebTestCase
{
    private const SECRET = 'gameserver-disk-test-secret';
    private const NODE = 'smoke-node-01';

    #[WithEnvironmentVariable('APP_ENCRYPTION_KEYS', 'v1:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=')]
    #[WithEnvironmentVariable('ACTIVE_ENCRYPTION_KEY_ID', 'v1')]
    public function testStoppedGameserverIsMeasuredRepeatedlyAndFailedResultsPreserveUsage(): void
    {
        $this->seedSite();
        $client = self::createClient();
        $client->disableReboot();
        $instance = $this->seedInstance();
        $id = $instance->getId();
        self::assertNotNull($id);
        $node = $instance->getNode();
        $node->setSecretPayload(self::getContainer()->get(EncryptionServiceInterface::class)->encrypt(self::SECRET));
        $node->setDiskScanIntervalSeconds(60);
        $node->recordHeartbeat(['os' => 'linux'], 'test', null, status: 'active');
        $instance->setInstanceBaseDir('/srv/game volume');
        $instance->setInstallPath('/srv/game volume/customer/game');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $url = '/instances/'.$id.'/disk-usage';
        $this->assertDiskCard($client, $url, 'unknown');

        $now = new \DateTimeImmutable();
        $scheduler = self::getContainer()->get(CentralSchedulerRunner::class);
        $jobs = self::getContainer()->get(JobRepositoryInterface::class);
        self::assertSame('success', $scheduler->runNow('core.disk_scan', 'system', 'core.disk_scan', $now)->status);
        $first = $jobs->findActiveByTypeAndPayloadField('instance.disk.scan', 'instance_id', (string) $id);
        self::assertNotNull($first);
        self::assertSame('/srv/game volume', $first->getPayload()['base_dir']);
        self::assertSame('/srv/game volume/customer/game', $first->getPayload()['instance_dir']);
        self::assertSame('skipped', $scheduler->runNow('core.disk_scan', 'system', 'core.disk_scan', $now)->status);

        $this->reportUsage($client, $first, $now, '2097152');
        $this->assertDiskCard($client, $url, 'current', '2.0 MB');
        self::assertSame('skipped', $scheduler->runNow('core.disk_scan', 'system', 'core.disk_scan', $now->modify('+30 seconds'))->status);

        $next = $now->modify('+61 seconds');
        self::assertSame('success', $scheduler->runNow('core.disk_scan', 'system', 'core.disk_scan', $next)->status);
        $second = $jobs->findActiveByTypeAndPayloadField('instance.disk.scan', 'instance_id', (string) $id);
        self::assertNotNull($second);
        self::assertNotSame($first->getId(), $second->getId());
        $this->reportUsage($client, $second, $next, '-1');
        $this->assertDiskCard($client, $url, 'stale', '2.0 MB');

        $retry = $next->modify('+61 seconds');
        self::assertSame('success', $scheduler->runNow('core.disk_scan', 'system', 'core.disk_scan', $retry)->status);
        $third = $jobs->findActiveByTypeAndPayloadField('instance.disk.scan', 'instance_id', (string) $id);
        self::assertNotNull($third);
        self::assertNotSame($second->getId(), $third->getId());
        $this->reportUsage($client, $third, $retry, '3145728');
        $this->assertDiskCard($client, $url, 'current', '3.0 MB');
    }

    private function assertDiskCard(KernelBrowser $client, string $url, string $state, ?string $value = null): void
    {
        $card = $client->request('GET', $url)->filterXPath('//*[@data-disk-measurement]');
        self::assertResponseIsSuccessful();
        self::assertSame($state, $card->attr('data-disk-measurement'));
        if ($value !== null) {
            self::assertStringContainsString($value, $card->text());
        } else {
            self::assertCount(0, $card->filterXPath('.//meter'));
        }
    }

    private function reportUsage(KernelBrowser $client, Job $job, \DateTimeImmutable $completedAt, string $bytes): void
    {
        $job->lock(self::NODE, 'disk-test-lock', $completedAt->modify('+10 minutes'));
        $job->claim(self::NODE, $completedAt);
        $job->transitionTo(JobStatus::Claimed);
        $job->transitionTo(JobStatus::Running);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $id = $job->getId();
        $path = '/api/v1/agent/jobs/'.$id.'/result';
        $body = json_encode([
            'job_id' => $id,
            'status' => 'success',
            'output' => ['used_bytes' => $bytes],
            'completed_at' => $completedAt->format(DATE_RFC3339),
        ], JSON_THROW_ON_ERROR);

        $nonce = bin2hex(random_bytes(16));
        $now = time();
        $timestamp = gmdate(DATE_RFC3339, $now);
        $signature = hash_hmac('sha256', AgentSignatureVerifier::buildSignaturePayload(self::NODE, 'POST', $path, $timestamp, $nonce, $body), self::SECRET);
        $base64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $container = self::getContainer();
        $claims = ['iss' => $container->getParameter('app.agent_jwt_issuer'), 'aud' => $container->getParameter('app.agent_jwt_audience'), 'sub' => self::NODE, 'iat' => $now, 'exp' => $now + 60, 'jti' => $nonce];
        $token = $base64('{"alg":"HS256"}').'.'.$base64(json_encode($claims, JSON_THROW_ON_ERROR));
        $token .= '.'.$base64(hash_hmac('sha256', $token, self::SECRET, true));
        $client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AGENT_ID' => self::NODE,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: $body);
        self::assertResponseIsSuccessful();
    }
}
