<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Gameserver\Application\MinecraftCatalogSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'catalog:sync:minecraft', description: 'Synchronize Vanilla, Paper, Spigot, Bedrock and BungeeCord from official sources.')]
final class CatalogSyncMinecraftCommand extends Command
{
    public function __construct(private readonly MinecraftCatalogSynchronizer $synchronizer) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->synchronizer->synchronize(true);
        $failed = false;
        foreach ($status['channels'] ?? [] as $channel => $result) {
            $failed = $failed || !empty($result['error']);
            $output->writeln($channel.': '.($result['error'] ?? ((string) $result['count'].' entries')));
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
