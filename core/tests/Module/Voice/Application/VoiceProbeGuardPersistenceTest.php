<?php

declare(strict_types=1);

namespace App\Tests\Module\Voice\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Entity\VoiceRateLimitState;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Voice\Application\DoctrineVoiceRateLimitStateStore;
use App\Module\Voice\Application\VoiceProbeGuard;
use App\Repository\VoiceRateLimitStateRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class VoiceProbeGuardPersistenceTest extends TestCase
{
    public function testFirstFailureReusesTheUnflushedTokenBucketState(): void
    {
        [$em, $guard, $node, $repository] = $this->fixture();
        $instance = $this->instance($node);
        self::assertNull($repository->findOneByNodeAndProvider($node, 'ts3'));

        self::assertTrue($guard->allow($instance)['allowed']);
        // An ordinary Doctrine query cannot see an insertion before flush.
        self::assertNull($repository->findOneByNodeAndProvider($node, 'ts3'));
        $guard->recordProbeResult($instance, 'unknown', 'voice_query_timeout');

        self::assertCount(1, $em->getUnitOfWork()->getScheduledEntityInsertions());
        $em->flush();
        $rows = $em->getConnection()->fetchAllAssociative('SELECT tokens, consecutive_failures FROM voice_rate_limit_states');
        self::assertCount(1, $rows);
        self::assertSame(4.0, (float) $rows[0]['tokens']);
        self::assertSame(1, (int) $rows[0]['consecutive_failures']);
    }

    public function testNewUnitOfWorkReadsPersistedStateRatherThanRetainingARequestCache(): void
    {
        [$em, $guard, $node] = $this->fixture();
        $guard->allow($this->instance($node));
        $guard->recordProbeResult($this->instance($node), 'unknown', 'voice_query_timeout');
        $em->flush();
        $em->getConnection()->executeStatement('UPDATE voice_rate_limit_states SET consecutive_failures = 2');
        $nodeId = $node->getId();
        $em->clear();
        $reloadedNode = $em->find(VoiceNode::class, $nodeId);
        self::assertInstanceOf(VoiceNode::class, $reloadedNode);

        $guard->recordProbeResult($this->instance($reloadedNode), 'unknown', 'voice_query_timeout');
        $em->flush();

        $row = $em->getConnection()->fetchAssociative('SELECT consecutive_failures, circuit_open_until FROM voice_rate_limit_states');
        self::assertIsArray($row);
        self::assertSame(3, (int) $row['consecutive_failures']);
        self::assertNotNull($row['circuit_open_until']);
    }

    /** @return array{EntityManager, VoiceProbeGuard, VoiceNode, VoiceRateLimitStateRepository} */
    private function fixture(): array
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 4).'/src/Module/Core/Domain/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $configuration);
        (new SchemaTool($em))->createSchema([
            $em->getClassMetadata(VoiceNode::class),
            $em->getClassMetadata(VoiceRateLimitState::class),
        ]);
        $node = new VoiceNode('first-probe', 'ts3', '127.0.0.1', 10011);
        $em->persist($node);
        $em->flush();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $repository = new VoiceRateLimitStateRepository($registry);
        $guard = new VoiceProbeGuard(new DoctrineVoiceRateLimitStateStore($repository), $em);

        return [$em, $guard, $node, $repository];
    }

    private function instance(VoiceNode $node): VoiceInstance
    {
        return new VoiceInstance(new User('voice-persistence@example.test', UserType::Customer), $node, '1', 'Voice');
    }
}
