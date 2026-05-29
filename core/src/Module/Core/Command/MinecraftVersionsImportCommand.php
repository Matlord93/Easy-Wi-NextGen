<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\MinecraftVersionImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'app:minecraft:versions:import', description: 'Import Minecraft versions from local panel files into the database. / Importiert Minecraft-Versionen aus lokalen Panel-Dateien in die Datenbank.')]
final class MinecraftVersionsImportCommand extends Command
{
    public function __construct(
        private readonly MinecraftVersionImportService $importService,
        private readonly TranslatorInterface $translator,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->trans('minecraft_versions_import_command_description'))
            ->addOption('all', null, InputOption::VALUE_NONE, $this->trans('minecraft_versions_import_option_all'))
            ->addOption('channel', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $this->trans('minecraft_versions_import_option_channel'))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, $this->trans('minecraft_versions_import_option_dry_run'))
            ->addOption('force', null, InputOption::VALUE_NONE, $this->trans('minecraft_versions_import_option_force'))
            ->addOption('deactivate-missing', null, InputOption::VALUE_NONE, $this->trans('minecraft_versions_import_option_deactivate_missing'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $channels = (array) $input->getOption('channel');
        if ($input->getOption('all') || $channels === []) {
            $channels = MinecraftVersionImportService::CHANNELS;
        }
        $channels = array_values(array_unique(array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $channels)));
        $invalid = array_diff($channels, MinecraftVersionImportService::CHANNELS);
        if ($invalid !== []) {
            $output->writeln(sprintf('<error>%s</error>', $this->trans('minecraft_versions_import_invalid_channels', ['%channels%' => implode(', ', $invalid)])));
            return Command::INVALID;
        }

        $summary = $this->importService->import(
            $channels,
            (bool) $input->getOption('dry-run'),
            (bool) $input->getOption('force'),
            (bool) $input->getOption('deactivate-missing'),
        );

        $output->writeln(sprintf('<info>%s</info>', $this->trans('minecraft_versions_import_summary', [
            '%mode%' => $summary['dryRun'] ? $this->trans('minecraft_versions_import_mode_dry_run') : $this->trans('minecraft_versions_import_mode_write'),
            '%channels%' => implode(', ', $channels),
        ])));
        $output->writeln($this->trans('minecraft_versions_import_created', ['%count%' => (string) $summary['created']]));
        $output->writeln($this->trans('minecraft_versions_import_updated', ['%count%' => (string) $summary['updated']]));
        $output->writeln($this->trans('minecraft_versions_import_skipped', ['%count%' => (string) $summary['skipped']]));
        $output->writeln($this->trans('minecraft_versions_import_deactivated', ['%count%' => (string) $summary['deactivated']]));
        foreach ($summary['errors'] as $error) {
            $output->writeln(sprintf('<error>%s</error>', $error));
        }

        return $summary['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'portal');
    }
}
