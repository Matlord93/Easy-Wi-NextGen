<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application;

use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class VmPasswordDelivery
{
    public function __construct(private EntityManagerInterface $em, private SecretsCryptoInterface $crypto, private LockFactory $locks)
    {
    }

    /** @param callable(string): string $apply Returns the operation ID, never the password. */
    public function change(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, VmRootPassword $password, callable $apply): void
    {
        $this->storeChange($assignment, $password, $apply, false);
    }

    /** @param callable(string): string $enqueue Persists a local job and returns its ID without changing the guest. */
    public function queueChange(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, VmRootPassword $password, callable $enqueue): string
    {
        return $this->storeChange($assignment, $password, $enqueue, true);
    }

    /** @param callable(string): string $apply */
    private function storeChange(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, VmRootPassword $password, callable $apply, bool $queued): string
    {
        $encrypted = $this->crypto->encrypt($password->password);
        $lock = $this->locks->createLock('vm.root-password.'.($assignment instanceof KvmGuestAssignment ? 'kvm' : 'proxmox').'.'.EntityIdentifier::require($assignment->getId()));
        if (!$lock->acquire()) {
            throw new \InvalidArgumentException('vm_config.root_password_busy');
        }
        $store = function () use ($assignment, $password, $apply, $encrypted): string {
            $operation = $apply($password->hash);
            $this->rememberCiphertext($assignment, $encrypted, $operation);
            $this->em->flush();

            return $operation;
        };
        try {
            return $queued ? $this->em->getConnection()->transactional($store) : $store();
        } catch (\Throwable $error) {
            if ($queued) {
                // Discard ORM state from any flush that preceded the rollback.
                $this->em->clear();
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public function remember(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, VmRootPassword $password, string $operation): void
    {
        $this->rememberCiphertext($assignment, $this->crypto->encrypt($password->password), $operation);
    }

    public function rememberCiphertext(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, #[\SensitiveParameter] string $ciphertext, string $operation): void
    {
        $assignment->setRootPasswordHandoff(['operation' => $operation, 'expires_at' => time() + 86400, 'ciphertext' => $ciphertext]);
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $db = $this->em->getConnection();
        $expiry = match (true) {
            $db->getDatabasePlatform() instanceof AbstractMySQLPlatform => "CAST(JSON_UNQUOTE(JSON_EXTRACT(root_password_handoff, '$.expires_at')) AS SIGNED)",
            $db->getDatabasePlatform() instanceof PostgreSQLPlatform => "CAST(root_password_handoff ->> 'expires_at' AS BIGINT)",
            $db->getDatabasePlatform() instanceof SQLitePlatform => "CAST(JSON_EXTRACT(root_password_handoff, '$.expires_at') AS INTEGER)",
            default => throw new \LogicException('Unsupported database platform for VM password cleanup.'),
        };
        $removed = 0;
        foreach (['kvm_guest_assignments', 'proxmox_guest_assignments'] as $table) {
            // Check the expiry during the update so a concurrent replacement stays available.
            $removed += (int) $db->executeStatement(
                'UPDATE '.$table.' SET root_password_handoff = NULL WHERE root_password_handoff IS NOT NULL AND '.$expiry.' <= ?',
                [$now->getTimestamp()],
                [ParameterType::INTEGER],
            );
        }

        return $removed;
    }

    /** @param callable(string): string $operationState */
    public function state(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, callable $operationState): ?string
    {
        $handoff = $assignment->getRootPasswordHandoff();

        return $handoff === null ? null : ($handoff['expires_at'] <= time() ? 'expired' : $operationState($handoff['operation']));
    }

    /** @param callable(string): string $operationState */
    public function reveal(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, callable $operationState): string
    {
        return $this->em->getConnection()->transactional(function () use ($assignment, $operationState): string {
            $table = $assignment instanceof KvmGuestAssignment ? 'kvm_guest_assignments' : 'proxmox_guest_assignments';
            // Serialize consumption across PHP workers before reloading the handoff.
            $this->em->getConnection()->executeStatement('UPDATE '.$table.' SET id = id WHERE id = ?', [EntityIdentifier::require($assignment->getId())]);
            $this->em->refresh($assignment);
            $handoff = $assignment->getRootPasswordHandoff();
            if ($handoff === null || $handoff['expires_at'] <= time() || 'ready' !== $operationState($handoff['operation'])) {
                throw new \InvalidArgumentException('vm_config.root_password_not_revealable');
            }
            $password = $this->crypto->decrypt($handoff['ciphertext']);
            $assignment->setRootPasswordHandoff(null);
            $this->em->flush();

            return $password;
        });
    }
}
