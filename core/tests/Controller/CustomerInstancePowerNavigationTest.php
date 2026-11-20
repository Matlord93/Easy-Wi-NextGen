<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class CustomerInstancePowerNavigationTest extends AbstractWebTestCase
{
    public static function powerActions(): iterable
    {
        yield 'start' => ['start', 'Start queued.'];
        yield 'stop' => ['stop', 'Stop queued.'];
        yield 'restart' => ['restart', 'Restart queued.'];
        yield 'suspended' => ['start', 'This instance is suspended.', true];
    }

    #[DataProvider('powerActions')]
    public function testHtmlPowerReturnsToOverviewWithItsResult(string $action, string $message, bool $suspended = false): void
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $id = $instance->getId();
        $instance->setStatus($suspended ? InstanceStatus::Suspended : ('start' === $action ? InstanceStatus::Stopped : InstanceStatus::Running));
        $instance->setQueryStatusCache(['status' => 'start' === $action ? 'offline' : 'online']);
        $instance->setQueryCheckedAt(new \DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);
        $client->request('POST', '/instances/'.$id.'/power', ['action' => $action]);
        self::assertResponseRedirects('/instances/'.$id.'/overview', 303);
        $job = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Job::class)->findBy(['type' => 'instance.'.$action]);
        $matching = array_filter($job, static fn (Job $job): bool => (string) $id === ($job->getPayload()['instance_id'] ?? null));
        self::assertCount($suspended ? 0 : 1, $matching);
        $page = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filterXPath('//*[@id="gameserver-overview-mount"]'));
        self::assertStringContainsString($message, $page->filterXPath('//*[@role="'.($suspended ? 'alert' : 'status').'"]')->text());
        // Refreshing the overview must not resubmit the power action or repeat its notice.
        $client->request('GET', '/instances/'.$id.'/overview');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($message, (string) $client->getResponse()->getContent());
    }
}
