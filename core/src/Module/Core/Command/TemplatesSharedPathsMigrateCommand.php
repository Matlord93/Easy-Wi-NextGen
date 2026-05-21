<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Domain\Entity\Template;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'templates:shared-paths:migrate',
    description: 'Backfill shared_paths into existing game templates that have none yet.',
)]
final class TemplatesSharedPathsMigrateCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly GameTemplateSeedCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite shared_paths even if already set.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing to the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry-run mode — no changes will be written.');
        }

        $sharedPathsByGameKey = $this->buildSharedPathIndex();

        $em = $this->registry->getManager();
        /** @var list<Template> $templates */
        $templates = $em->getRepository(Template::class)->findAll();

        $updated = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            $gameKey = $template->getGameKey();

            if (!isset($sharedPathsByGameKey[$gameKey])) {
                continue;
            }

            $requirements = $template->getRequirements();
            $alreadyHasPaths = isset($requirements['shared_paths']) && is_array($requirements['shared_paths']) && $requirements['shared_paths'] !== [];

            if ($alreadyHasPaths && !$force) {
                $skipped++;
                $io->writeln(sprintf('  skip  <comment>%s</comment> (already has shared_paths; use --force to overwrite)', $gameKey));
                continue;
            }

            $requirements['shared_paths'] = $sharedPathsByGameKey[$gameKey];
            $io->writeln(sprintf('  set   <info>%s</info> → %d path(s)', $gameKey, count($requirements['shared_paths'])));

            if (!$dryRun) {
                $template->setRequirements($requirements);
            }
            $updated++;
        }

        if (!$dryRun && $updated > 0) {
            $em->flush();
        }

        $io->success(sprintf(
            '%s %d template(s); skipped %d (already configured).',
            $dryRun ? 'Would update' : 'Updated',
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<array{source:string,target:string,mode:string,readonly:bool}>>
     */
    private function buildSharedPathIndex(): array
    {
        $index = [];
        foreach ($this->catalog->listTemplates() as $tpl) {
            $gameKey = (string) ($tpl['game_key'] ?? '');
            $paths = $tpl['shared_paths'] ?? [];
            if ($gameKey !== '' && is_array($paths) && $paths !== []) {
                $index[$gameKey] = $paths;
            }
        }

        return $index;
    }
}
