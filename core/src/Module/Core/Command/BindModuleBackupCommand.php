<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\Backup\Resource\ModuleBackupBindingStore;
use App\Module\Core\Application\Backup\Resource\ModuleBackupResourceResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:backup-modules:bind', description: 'Administrator: configure the management Agent, resource roots and service for complete module backups from a private local JSON file.')]
final class BindModuleBackupCommand extends Command
{
    public function __construct(private readonly ModuleBackupBindingStore $bindings, private readonly ModuleBackupResourceResolver $resources, private readonly Connection $connection)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED, 'mail, voice, game or db.')
            ->addArgument('resource', InputArgument::REQUIRED, 'Resource ID; voice: ts3:<instance-id> or ts6:<instance-id>.')
            ->addArgument('file', InputArgument::REQUIRED, 'Private JSON: agent_id, path, service, optional additional_paths.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $module = $input->getArgument('module');
        $resource = $input->getArgument('resource');
        $path = $input->getArgument('file');
        if (!is_file($path) || is_link($path) || str_contains($path, '://') || ((int) fileperms($path) & 0077) !== 0) {
            $output->writeln('<error>Provide a private, readable local JSON file (chmod 600).</error>');
            return self::INVALID;
        }
        try {
            $json = file_get_contents($path, false, null, 0, 65537);
            if ($json === false || strlen($json) > 65536) {
                throw new \InvalidArgumentException('Binding file exceeds 64 KiB.');
            }
            $binding = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($binding)) {
                throw new \InvalidArgumentException('Binding must be a JSON object.');
            }
            $this->connection->transactional(function () use ($module, $resource, $binding): void {
                $this->bindings->save($module, $resource, $binding);
                $this->resources->resolve($module, $resource);
            });
        } catch (\Throwable) {
            $output->writeln('<error>Binding could not be configured or validated. Check resource ownership, management Agent, service, paths and migrations. No Agent operations were executed.</error>');
            return self::FAILURE;
        }
        $output->writeln('Module backup binding saved and resource validated. Configure matching module roots on the Agent before running backups.');
        return self::SUCCESS;
    }
}
