<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\GameTemplateSeedSyncService;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'gameserver:templates:sync-seed', description: 'Sync template fields from seed catalog.')]
final class TemplatesSyncSeedCommand extends Command
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
        private readonly GameTemplateSeedSyncService $seedSyncService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('template', null, InputOption::VALUE_REQUIRED, 'Template game_key to sync.');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Sync all templates.');
        $this->addOption('field', null, InputOption::VALUE_REQUIRED, 'Field to sync', 'shared_paths');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ((string) $input->getOption('field') !== 'shared_paths') {
            $io->error('Only --field=shared_paths is currently supported.');
            return self::FAILURE;
        }

        $templates = [];
        if ((bool) $input->getOption('all')) {
            $templates = $this->templateRepository->findAll();
        } else {
            $key = trim((string) $input->getOption('template'));
            if ($key === '') {
                $io->error('Use --template=<game_key> or --all.');
                return self::FAILURE;
            }
            $template = $this->templateRepository->findOneBy(['gameKey' => $key]);
            if ($template === null) {
                $io->error(sprintf('Template not found for key "%s".', $key));
                return self::FAILURE;
            }
            $templates = [$template];
        }

        $updated = 0;
        foreach ($templates as $template) {
            $comparison = $this->seedSyncService->compareSharedPaths($template);
            $io->section(sprintf('Template %s (id=%s)', $template->getGameKey(), (string) $template->getId()));
            $io->writeln('Old shared_paths: '.json_encode($comparison['current'], JSON_UNESCAPED_SLASHES));
            $io->writeln('New shared_paths: '.json_encode($comparison['seed'], JSON_UNESCAPED_SLASHES));
            $changed = $this->seedSyncService->syncSharedPaths($template);
            if ($changed) {
                $this->entityManager->persist($template);
                $updated++;
                $io->success('Saved updated shared_paths.');
            } else {
                $io->writeln('No change needed.');
            }
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }
        $io->success(sprintf('Done. Updated %d template(s).', $updated));

        return self::SUCCESS;
    }
}
