<?php

declare(strict_types=1);

namespace App\Module\EasyWiMigration\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class EasyWiUserImporter
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function import(EasyWiSourceReader $reader, bool $dryRun = false): EasyWiMigrationResult
    {
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $offset = 0;

        do {
            $rows = $reader->fetchUsers($offset, 200);
            foreach ($rows as $row) {
                try {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '') {
                        $failed++;
                        $errors[] = sprintf('Row %s: missing email.', $row['id'] ?? '?');
                        continue;
                    }

                    $existing = $this->userRepository->findOneBy(['email' => $email]);
                    if ($existing !== null) {
                        $skipped++;
                        continue;
                    }

                    $type = $this->resolveUserType((string) ($row['type'] ?? 'customer'));
                    $user = new User($email, $type);

                    // Preserve the legacy bcrypt/phpass hash without re-hashing.
                    $hash = (string) ($row['password'] ?? '');
                    if ($hash !== '') {
                        $user->setPasswordHash($hash);
                    }

                    if (method_exists($user, 'setFirstName') && isset($row['firstname'])) {
                        $user->setFirstName((string) $row['firstname']);
                    }
                    if (method_exists($user, 'setLastName') && isset($row['lastname'])) {
                        $user->setLastName((string) $row['lastname']);
                    }

                    $user->setEmailVerifiedAt(new \DateTimeImmutable());

                    if (!$dryRun) {
                        $this->entityManager->persist($user);
                    }

                    $imported++;
                } catch (\Throwable $e) {
                    $failed++;
                    $id = $row['id'] ?? '?';
                    $errors[] = sprintf('Row %s: %s', $id, $e->getMessage());
                    $this->logger->warning('easywi.migration.user_failed', ['id' => $id, 'error' => $e->getMessage()]);
                }
            }

            if (!$dryRun && $imported % 100 === 0 && $imported > 0) {
                $this->entityManager->flush();
                $this->entityManager->clear(User::class);
            }

            $offset += count($rows);
        } while (count($rows) === 200);

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return new EasyWiMigrationResult('users', $imported, $skipped, $failed, $errors);
    }

    private function resolveUserType(string $legacyType): UserType
    {
        return match (strtolower($legacyType)) {
            'admin', 'superadmin' => UserType::Admin,
            'reseller' => UserType::Reseller,
            default => UserType::Customer,
        };
    }
}
