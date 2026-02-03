<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Infrastructure\Config\DbConfigProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:diagnose:db',
    description: 'Diagnose database connection and migration status.',
)]
final class DatabaseDiagnoseCommand extends Command
{
    public function __construct(
        private readonly DbConfigProvider $configProvider,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $overallOk = true;

        $io->title('Database diagnostics');

        $io->section('Key file');
        if ($this->configProvider->isKeyReadable()) {
            $io->success(sprintf('Key file readable at %s', $this->configProvider->getKeyPath()));
        } else {
            $io->error(sprintf('Key file not readable at %s', $this->configProvider->getKeyPath()));
            $overallOk = false;
        }

        $io->section('Config file');
        if (!$this->configProvider->exists()) {
            $io->error('DB nicht konfiguriert.');
            return Command::FAILURE;
        }

        try {
            $payload = $this->configProvider->load();
        } catch (\Throwable $exception) {
            $io->error('Database configuration could not be decrypted.');
            $this->logger->error('Database config decrypt failed in diagnose command.', [
                'exception' => $exception,
            ]);
            return Command::FAILURE;
        }

        $validationErrors = $this->configProvider->validate($payload);
        if ($validationErrors !== []) {
            $io->error('Database configuration is invalid.');
            $io->listing($validationErrors);
            $overallOk = false;
            $io->warning('Skipping connection test (invalid configuration).');
            return Command::FAILURE;
        } else {
            $io->success('Database configuration loaded.');
        }

        $connectionParams = $this->configProvider->toConnectionParams($payload);

        $connection = null;

        $io->section('Connection test');
        try {
            $connection = DriverManager::getConnection($connectionParams);
            $connection->executeQuery('SELECT 1');
            $io->success('Database connection OK.');
        } catch (DbalException|\Throwable $exception) {
            $io->error(sprintf('Database connection failed: %s', $exception->getMessage()));
            $this->logger->error('Database connection failed in diagnose command.', [
                'exception' => $exception,
            ]);
            $overallOk = false;
        }

        $io->section('Migrations');
        if (!$connection instanceof Connection) {
            $io->warning('Skipping migrations (no database connection).');
            return $overallOk ? Command::SUCCESS : Command::FAILURE;
        }
        try {
            $migrationConfig = new ConfigurationArray([
                'migrations_paths' => [
                    'DoctrineMigrations' => $this->projectDir . '/migrations',
                ],
                'transactional' => false,
                'all_or_nothing' => false,
            ]);
            $dependencyFactory = DependencyFactory::fromConnection(
                $migrationConfig,
                new ExistingConnection($connection),
            );
            $statusCalculator = $dependencyFactory->getMigrationStatusCalculator();
            $newMigrations = $statusCalculator->getNewMigrations();
            $executedUnavailable = $statusCalculator->getExecutedUnavailableMigrations();

            $io->text(sprintf('New migrations: %d', count($newMigrations)));
            $io->text(sprintf('Executed but unavailable: %d', count($executedUnavailable)));

            if (count($executedUnavailable) > 0) {
                $overallOk = false;
                $io->warning('Some executed migrations are missing from the codebase.');
            }
        } catch (\Throwable $exception) {
            $io->error(sprintf('Failed to read migration status: %s', $exception->getMessage()));
            $this->logger->error('Migration status check failed in diagnose command.', [
                'exception' => $exception,
            ]);
            $overallOk = false;
        }

        return $overallOk ? Command::SUCCESS : Command::FAILURE;
    }
}
