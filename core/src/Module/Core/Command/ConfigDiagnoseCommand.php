<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Infrastructure\Config\DbConfigProvider;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:diagnose:config',
    description: 'Diagnose secure configuration (encryption key, DB config, DB connectivity).',
)]
final class ConfigDiagnoseCommand extends Command
{
    public function __construct(
        private readonly DbConfigProvider $configProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $overallOk = true;

        $io->title('Secure configuration diagnostics');

        $io->section('Encryption key');
        if ($this->configProvider->isKeyReadable()) {
            $io->success(sprintf('Key file readable at %s', $this->configProvider->getKeyPath()));
        } else {
            $io->error(sprintf('Key file not readable at %s', $this->configProvider->getKeyPath()));
            $overallOk = false;
        }

        $io->section('Database config');
        if (!$this->configProvider->exists()) {
            $io->error('No encrypted database config found.');
            $overallOk = false;
        }

        $params = null;
        if ($this->configProvider->exists()) {
            try {
                $payload = $this->configProvider->load();
                $validationErrors = $this->configProvider->validate($payload);
                if ($validationErrors !== []) {
                    $io->error('Database config is invalid.');
                    $io->listing($validationErrors);
                    $overallOk = false;
                } else {
                    $params = $this->configProvider->toConnectionParams($payload);
                    $io->success('Database config decrypted successfully.');
                }
            } catch (\Throwable $exception) {
                $io->error('Database config could not be decrypted.');
                $overallOk = false;
            }
        }

        $io->section('Database connectivity');
        if ($params === null) {
            $io->warning('Skipping DB connection test (missing config).');
            return $overallOk ? Command::SUCCESS : Command::FAILURE;
        }

        try {
            $connection = DriverManager::getConnection($params);
            $connection->executeQuery('SELECT 1');
            $io->success('Database connection OK.');
        } catch (DbalException|\Throwable $exception) {
            $io->error(sprintf('Database connection failed: %s', $exception->getMessage()));
            $overallOk = false;
        }

        return $overallOk ? Command::SUCCESS : Command::FAILURE;
    }
}
