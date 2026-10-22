<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\GameTemplateSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'minecraft:templates:prepare', description: 'Add missing Minecraft templates without changing existing templates.')]
final class MinecraftTemplatesPrepareCommand extends Command
{
    public function __construct(private readonly GameTemplateSeeder $seeder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->seeder->seedTemplatesOnly(null, ['minecraft_vanilla_all', 'minecraft_paper_all', 'minecraft_spigot_all', 'minecraft_bedrock', 'minecraft_bungeecord_all'], true);
        $output->writeln(sprintf('%d missing Minecraft templates added; existing templates preserved.', $result['created']));

        return Command::SUCCESS;
    }
}
