<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\DiskScanScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'disk:scan:reconcile', description: 'Queue instance disk scans and node disk stat jobs.')]
final class DiskScanReconcileCommand extends Command
{
    public function __construct(private readonly DiskScanScheduler $scheduler)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->scheduler->run(new \DateTimeImmutable());
        $output->writeln(sprintf('Queued %d instance disk scan(s).', $result['scans']));
        $output->writeln(sprintf('Queued %d node disk stat job(s).', $result['stats']));

        return Command::SUCCESS;
    }
}
