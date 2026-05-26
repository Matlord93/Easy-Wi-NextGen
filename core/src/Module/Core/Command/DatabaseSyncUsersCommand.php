<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseNodeInspector;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Database;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'database:sync-users', description: 'Repairs database users for managed customer databases.')]
final class DatabaseSyncUsersCommand extends Command
{
    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly DatabaseProvisioningService $provisioningService,
        private readonly DatabaseNamingPolicy $namingPolicy,
        private readonly DatabaseNodeInspector $nodeInspector,
        private readonly EncryptionService $encryptionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $databases = $this->databaseRepository->findBy([], ['id' => 'ASC']);
        $checked = 0;
        $repaired = 0;
        $skipped = 0;
        $failed = 0;
        $missingDatabase = 0;
        $missingUser = 0;
        $missingGrants = 0;
        $missingSecret = 0;

        foreach ($databases as $database) {
            ++$checked;
            if (!$database instanceof Database) {
                ++$skipped;
                continue;
            }
            $customerId = $database->getCustomer()->getId();
            $scopedName = $this->namingPolicy->buildCustomerScopedName($customerId, $database->getName());
            $needsUsernameRepair = trim($database->getUsername()) === '';
            $needsNameRepair = trim($database->getName()) === '';

            if ($needsNameRepair) {
                $databaseName = $scopedName;
            } else {
                $databaseName = $database->getName();
            }

            if ($needsUsernameRepair) {
                $database->setUsername($databaseName);
            }
            if ($needsNameRepair) {
                $database->setName($databaseName);
            }

            $needsPasswordRepair = !is_array($database->getEncryptedPassword());
            if ($needsPasswordRepair) {
                ++$missingSecret;
            }

            try {
                $inspection = $this->nodeInspector->inspect($database);
            } catch (\Throwable) {
                ++$failed;
                continue;
            }

            if ($inspection['database_exists'] === false) {
                ++$missingDatabase;
            }
            if ($inspection['user_exists'] === false) {
                ++$missingUser;
            }
            if ($inspection['grants_ok'] === false) {
                ++$missingGrants;
            }

            $needsNodeRepair = !$inspection['user_exists'] || !$inspection['grants_ok'];

            if ($needsNameRepair || $needsUsernameRepair || $needsPasswordRepair || $needsNodeRepair) {
                $database->setStatus('pending');
                $database->setLastError(null, null);
                if ($needsPasswordRepair) {
                    $database->setEncryptedPassword($this->encryptionService->encrypt(bin2hex(random_bytes(18))));
                }
                $agentId = $database->getNode()?->getAgent()->getId() ?? '';
                if ($agentId === '') {
                    ++$failed;
                    continue;
                }
                $job = $this->provisioningService->buildCreateJobs($database, $agentId)[0];
                $this->entityManager->persist($job);
                ++$repaired;
            } else {
                ++$skipped;
            }
        }

        $this->entityManager->flush();
        $io->success($this->translator->trans('database_sync_success', ['%count%' => (string) $repaired]));
        $io->writeln($this->translator->trans('database_sync_stats', [
            '%checked%' => (string) $checked,
            '%repaired%' => (string) $repaired,
            '%skipped%' => (string) $skipped,
            '%failed%' => (string) $failed,
        ]));
        $io->writeln($this->translator->trans('database_sync_stats_extended', [
            '%missing_database%' => (string) $missingDatabase,
            '%missing_user%' => (string) $missingUser,
            '%missing_grants%' => (string) $missingGrants,
            '%missing_secret%' => (string) $missingSecret,
        ]));

        return Command::SUCCESS;
    }
}
