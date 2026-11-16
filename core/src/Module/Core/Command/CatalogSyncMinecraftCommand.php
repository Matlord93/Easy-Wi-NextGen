<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\ScalarValue;

use App\Module\Gameserver\Application\MinecraftCatalogSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'catalog:sync:minecraft', description: 'Synchronize Vanilla, Paper, Spigot, Bedrock and BungeeCord from official sources.')]
final class CatalogSyncMinecraftCommand extends Command
{
    public function __construct(private readonly MinecraftCatalogSynchronizer $synchronizer) { parent::__construct(); }
    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->synchronizer->synchronize(true);
        $failed = false;
        $channels = JsonArray::orEmpty($status['channels'] ?? null);
        foreach (array_keys($channels) as $channel) {
            $result = JsonArray::orEmpty($channels[$channel]);
            $failed = $failed || !empty($result['error']);
            $output->writeln($channel.': '.ScalarValue::text($result['error'] ?? (ScalarValue::text($result['count'] ?? 0).' entries')));
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
