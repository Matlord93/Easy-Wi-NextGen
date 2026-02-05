<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Gameserver\Application\GameServerPathResolver;
use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gameserver:install-path:migrate',
    description: 'Backfill and validate canonical gameserver install_path values.',
)]
final class GameServerInstallPathMigrateCommand extends Command
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceFilesystemResolver $legacyFilesystemResolver,
        private readonly GameServerPathResolver $pathResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $instances = $this->instanceRepository->findBy([], ['id' => 'ASC']);

        $updated = 0;
        $broken = 0;

        foreach ($instances as $instance) {
            $path = trim((string) $instance->getInstallPath());
            if ($path === '') {
                $path = $this->legacyFilesystemResolver->resolveInstanceDir($instance);
                $instance->setInstallPath($path);
                $updated++;
            }

            try {
                $this->pathResolver->assertExistsAndAccessible($path);
                $instance->setSetupVar('install_path_state', 'OK');
            } catch (\Throwable $exception) {
                $instance->setSetupVar('install_path_state', 'BROKEN');
                $broken++;
                $this->logger->warning('gameserver.install_path_broken', [
                    'server_id' => $instance->getId(),
                    'install_path' => $path,
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->entityManager->persist($instance);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Processed %d servers. Updated paths: %d. Broken: %d.', count($instances), $updated, $broken));

        return $broken > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
