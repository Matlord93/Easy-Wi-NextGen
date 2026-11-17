<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Backup\BackupRetentionRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backup-plans:prune', description: 'Preview local archive retention for an enabled plan; use --apply to delete verified candidates.')]
final class PruneBackupPlanCommand extends Command
{
    public function __construct(private readonly BackupRetentionRunner $runner)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('plan', InputArgument::REQUIRED, 'Stored backup plan ID.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Delete verified recorded archives selected by the plan retention policy.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->runner->prunePlan($input->getArgument('plan'), $input->getOption('apply'));
        $output->writeln(OutputFormatter::escape($report->message));
        $output->writeln(sprintf('Plan: %s; status: %s; candidates: %d; deleted: %d; blocked: %d.', OutputFormatter::escape($report->planId), $report->status, count($report->candidates), count($report->deleted), count($report->blocked)));
        foreach ($report->candidates as $path) {
            $output->writeln('Candidate: '.OutputFormatter::escape($path));
        }
        foreach ($report->blocked as $blocked) {
            $output->writeln('Blocked: '.OutputFormatter::escape($blocked['path'].' — '.$blocked['reason']));
        }

        return in_array($report->status, ['validated', 'applied', 'disabled'], true) ? self::SUCCESS : self::FAILURE;
    }
}
