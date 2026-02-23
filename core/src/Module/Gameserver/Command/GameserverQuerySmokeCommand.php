<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Command;

use App\Module\Gameserver\Application\Query\QuerySmokeRunnerInterface;
use App\Module\Gameserver\Application\Query\TemplateEngineFamilyResolver;
use App\Repository\InstanceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'gameserver:query-smoke', description: 'Run query smoke checks across representative gameserver instances.')]
final class GameserverQuerySmokeCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly QuerySmokeRunnerInterface $querySmokeService,
        private readonly TemplateEngineFamilyResolver $engineFamilyResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('instances', null, InputOption::VALUE_REQUIRED, 'Comma-separated instance ids')
            ->addOption('engines', null, InputOption::VALUE_REQUIRED, 'Comma-separated engine families', 'source1,source2,minecraft_java,bedrock')
            ->addOption('max-per-engine', null, InputOption::VALUE_REQUIRED, 'Max instances per engine', '2')
            ->addOption('fail-fast', null, InputOption::VALUE_NONE, 'Stop at first failure')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $failFast = (bool) $input->getOption('fail-fast');

        $engines = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('engines')))));
        $maxPerEngine = max(1, (int) $input->getOption('max-per-engine'));
        $selected = $this->selectInstances((string) $input->getOption('instances'), $engines, $maxPerEngine);

        $report = [];
        $failed = false;
        foreach ($selected as $instance) {
            $result = $this->querySmokeService->run($instance, true);
            $report[] = $result;

            if (($result['ok'] ?? false) !== true) {
                $failed = true;
                if ($failFast) {
                    break;
                }
            }
        }

        if ($json) {
            $output->writeln((string) json_encode(['ok' => !$failed, 'results' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $rows = [];
            foreach ($report as $item) {
                $debug = is_array($item['debug'] ?? null) ? $item['debug'] : [];
                $rows[] = [
                    $item['engine'] ?? '',
                    (string) ($item['instance_id'] ?? ''),
                    (string) ($item['game'] ?? ''),
                    sprintf('%s:%s', (string) ($debug['resolved_host'] ?? '?'), (string) ($debug['resolved_port'] ?? '?')),
                    ($item['ok'] ?? false) ? 'PASS' : 'FAIL',
                    (string) ($item['players'] ?? '-'),
                    (string) ($item['map'] ?? '-'),
                    (string) ($item['latency_ms'] ?? '-'),
                    (string) ($item['request_id'] ?? ''),
                ];

                if (($item['ok'] ?? false) !== true) {
                    $io->writeln(sprintf('FAIL instance=%s code=%s message=%s request_id=%s debug=%s', (string) ($item['instance_id'] ?? ''), (string) ($item['error_code'] ?? ''), (string) ($item['error_message'] ?? ''), (string) ($item['request_id'] ?? ''), (string) json_encode($debug)));
                }
            }

            $io->table(['engine', 'instance_id', 'game', 'resolved_host:port', 'ok', 'players', 'map', 'latency', 'request_id'], $rows);
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<\App\Module\Core\Domain\Entity\Instance> */
    private function selectInstances(string $instanceIds, array $engines, int $maxPerEngine): array
    {
        $all = $this->instanceRepository->findAll();
        if (trim($instanceIds) !== '') {
            $wanted = array_map('intval', array_filter(array_map('trim', explode(',', $instanceIds))));
            return array_values(array_filter($all, static fn ($i) => in_array((int) ($i->getId() ?? 0), $wanted, true)));
        }

        $counts = [];
        $selected = [];
        foreach ($all as $instance) {
            $engine = $this->engineFamilyResolver->resolve($instance->getTemplate());
            if (!in_array($engine, $engines, true)) {
                continue;
            }
            $counts[$engine] = ($counts[$engine] ?? 0);
            if ($counts[$engine] >= $maxPerEngine) {
                continue;
            }
            $counts[$engine]++;
            $selected[] = $instance;
        }

        return $selected;
    }
}
