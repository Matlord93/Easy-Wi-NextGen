<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\MetricsLiveBuffer;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminMetricsController;
use App\Repository\AgentRepository;
use App\Repository\InstanceMetricSampleRepository;
use App\Repository\InstanceRepository;
use App\Repository\MetricSampleRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AdminMetricsControllerTest extends TestCase
{
    public function testLiveFetchesOnlyTheRequestedAgent(): void
    {
        $agent = $this->agent();
        $agents = $this->createMock(AgentRepository::class);
        $agents->expects(self::once())->method('find')->with('node-1')->willReturn($agent);
        $agents->expects(self::never())->method('findBy');
        $registry = $this->createStub(ManagerRegistry::class);
        $controller = $this->controller(
            $agents,
            $this->createStub(InstanceRepository::class),
            new MetricSampleRepository($registry),
            new InstanceMetricSampleRepository($registry),
            $this->createStub(Environment::class),
        );

        $response = $controller->live($this->request('/admin/metrics/live?agent=node-1'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('node-1', $payload['agent_id']);
        self::assertSame('Node One', $payload['agent_name']);
        self::assertNull($payload['current']);
        self::assertSame([], $payload['samples']);
    }

    public function testIndexReusesSelectedInstanceAndAggregateCounts(): void
    {
        $agent = $this->agent();
        $agents = $this->createStub(AgentRepository::class);
        $agents->method('findBy')->willReturn([$agent]);
        $customer = new User('customer@example.test', UserType::Customer);
        $instance = $this->createStub(Instance::class);
        $instance->method('getId')->willReturn(7);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getNode')->willReturn($agent);
        $instance->method('getCpuLimit')->willReturn(2);
        $instance->method('getRamLimit')->willReturn(1024);
        $instances = $this->createMock(InstanceRepository::class);
        $instances->expects(self::once())->method('find')->with(7)->willReturn($instance);
        $instances->method('findBy')->willReturn([$instance]);

        $hostSamples = $this->repositoryWithQueries(MetricSampleRepository::class, [
            $this->query(['sample_count' => '7', 'cpu_avg' => null, 'cpu_max' => null, 'memory_avg' => null, 'memory_max' => null, 'disk_avg' => null, 'disk_max' => null]),
            $this->query(),
            $this->query(),
        ]);
        $instanceSamples = $this->repositoryWithQueries(InstanceMetricSampleRepository::class, [
            $this->query(),
            $this->query(),
            $this->query(['sample_count' => '5', 'cpu_avg' => null, 'cpu_max' => null, 'mem_avg' => null, 'mem_max' => null]),
            $this->query(),
            $this->query(),
        ]);
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with('admin/metrics/index.html.twig', self::callback(static function (array $context) use ($agent): bool {
            self::assertSame($agent, $context['selectedAgent']);
            self::assertSame(7, $context['totalSamples']);
            self::assertSame(5, $context['instanceMetricTotal']);
            self::assertSame(7, $context['selectedInstance']['id']);
            self::assertNull($context['loadError']);

            return true;
        }))->willReturn('metrics');
        $controller = $this->controller($agents, $instances, $hostSamples, $instanceSamples, $twig);

        self::assertSame('metrics', $controller->index($this->request('/admin/metrics?instance=7'))->getContent());
    }

    private function controller(AgentRepository $agents, InstanceRepository $instances, MetricSampleRepository $hostSamples, InstanceMetricSampleRepository $instanceSamples, Environment $twig): AdminMetricsController
    {
        return new AdminMetricsController($agents, $instances, $hostSamples, $instanceSamples, new MetricsLiveBuffer(new ArrayAdapter()), $twig, new NullLogger(), 120, $this->createStub(TranslatorInterface::class));
    }

    private function request(string $url): Request
    {
        $request = Request::create($url);
        $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));

        return $request;
    }

    private function agent(): Agent
    {
        return new Agent('node-1', ['key_id' => 'test', 'nonce' => 'test', 'ciphertext' => 'test'], 'Node One');
    }

    private function query(?array $aggregate = null): Query
    {
        $query = $this->createStub(Query::class);
        $query->method('getSingleResult')->willReturn($aggregate);
        $query->method('getSingleScalarResult')->willReturn('0');
        $query->method('getArrayResult')->willReturn([]);

        return $query;
    }

    /**
     * @template T of MetricSampleRepository|InstanceMetricSampleRepository
     * @param class-string<T> $class
     * @param list<Query> $queries
     * @return T
     */
    private function repositoryWithQueries(string $class, array $queries): ServiceEntityRepository
    {
        $builders = [];
        foreach ($queries as $query) {
            $builder = $this->createStub(QueryBuilder::class);
            foreach (['select', 'addSelect', 'andWhere', 'setParameter', 'orderBy', 'addOrderBy', 'setFirstResult', 'setMaxResults', 'innerJoin', 'leftJoin'] as $method) {
                $builder->method($method)->willReturnSelf();
            }
            $builder->method('getQuery')->willReturn($query);
            $builders[] = $builder;
        }
        $inner = $this->createMock(EntityRepository::class);
        $inner->expects(self::exactly(count($builders)))->method('createQueryBuilder')->willReturnOnConsecutiveCalls(...$builders);
        $repository = new $class($this->createStub(ManagerRegistry::class));
        (new \ReflectionProperty(ServiceEntityRepository::class, 'repository'))->setValue($repository, $inner);

        return $repository;
    }
}
