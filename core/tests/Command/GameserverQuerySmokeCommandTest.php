<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Gameserver\Application\Query\QuerySmokeRunnerInterface;
use App\Module\Gameserver\Application\Query\TemplateEngineFamilyResolver;
use App\Module\Gameserver\Command\GameserverQuerySmokeCommand;
use App\Repository\InstanceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GameserverQuerySmokeCommandTest extends TestCase
{
    public function testReturnsSuccessWhenAllPass(): void
    {
        $instance = $this->instance(1, 'l4d2');

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('findAll')->willReturn([$instance]);

        $service = $this->createMock(QuerySmokeRunnerInterface::class);
        $service->method('run')->willReturn([
            'engine' => 'source1',
            'instance_id' => 1,
            'game' => 'l4d2',
            'ok' => true,
            'request_id' => 'req-1',
            'debug' => ['resolved_host' => '127.0.0.1', 'resolved_port' => 27015],
        ]);

        $command = new GameserverQuerySmokeCommand($repo, $service, new TemplateEngineFamilyResolver());
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--instances' => '1']);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testReturnsFailureWhenOneFails(): void
    {
        $instance = $this->instance(1, 'l4d2');

        $repo = $this->createMock(InstanceRepository::class);
        $repo->method('findAll')->willReturn([$instance]);

        $service = $this->createMock(QuerySmokeRunnerInterface::class);
        $service->method('run')->willReturn([
            'engine' => 'source1',
            'instance_id' => 1,
            'game' => 'l4d2',
            'ok' => false,
            'request_id' => 'req-1',
            'error_code' => 'QUERY_TIMEOUT',
            'error_message' => 'timed out',
            'debug' => ['resolved_host' => '127.0.0.1', 'resolved_port' => 27015],
        ]);

        $command = new GameserverQuerySmokeCommand($repo, $service, new TemplateEngineFamilyResolver());
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--instances' => '1']);

        self::assertSame(Command::FAILURE, $exit);
    }

    private function instance(int $id, string $gameKey): Instance
    {
        $template = $this->createMock(Template::class);
        $template->method('getGameKey')->willReturn($gameKey);
        $template->method('getRequirements')->willReturn(['query' => ['type' => 'steam_a2s']]);

        $instance = $this->createMock(Instance::class);
        $instance->method('getId')->willReturn($id);
        $instance->method('getTemplate')->willReturn($template);

        return $instance;
    }
}
