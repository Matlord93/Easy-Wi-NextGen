<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\CmsTemplateSeeder;
use App\Module\Core\Application\GameTemplateSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'templates:seed',
    description: 'Seed game templates, plugin catalog entries, and CMS demo templates.',
)]
final class TemplatesSeedCommand extends Command
{
    public function __construct(
        private readonly GameTemplateSeeder $seeder,
        private readonly CmsTemplateSeeder $cmsSeeder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->seeder->seed();

        $io->success(sprintf(
            'Seeded %d game template(s), imported %d plugin(s), updated %d plugin(s), skipped %d plugin(s) because template keys were missing.',
            $result['templates'],
            $result['plugins'],
            $result['plugins_updated'],
            $result['skipped_missing_template'],
        ));

        if ($result['missing_game_keys'] !== []) {
            $io->warning(sprintf('Missing game_key(s): %s', implode(', ', $result['missing_game_keys'])));
        }

        $cmsResult = $this->cmsSeeder->seed();

        $io->success(sprintf(
            'Seeded %d CMS demo template(s) (%d already existed).',
            $cmsResult['created'],
            $cmsResult['skipped'],
        ));

        return Command::SUCCESS;
    }
}
