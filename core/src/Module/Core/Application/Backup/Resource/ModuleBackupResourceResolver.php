<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Resource;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\Ts3Instance;
use App\Module\Core\Domain\Entity\Ts6Instance;
use App\Module\Core\Domain\Enum\Ts3DatabaseMode;
use Doctrine\ORM\EntityManagerInterface;

final class ModuleBackupResourceResolver
{
    public function __construct(private readonly EntityManagerInterface $entities, private readonly ModuleBackupBindingStore $bindings, private readonly EncryptionService $encryption)
    {
    }

    public function resolve(string $module, string $resourceId): ManagedBackupResource
    {
        $id = $resourceId;
        $type = '';
        if ($module === 'voice') {
            if (!preg_match('/^(ts3|ts6):([1-9][0-9]*)$/D', $resourceId, $parts)) {
                throw new \InvalidArgumentException('Voice full backups require ts3:<instance-id> or ts6:<instance-id>; virtual-server snapshots use the existing ServerQuery workflow.');
            }
            $type = $parts[1];
            $id = $parts[2];
        }
        if (!ctype_digit($id) || (int) $id < 1 || (string) (int) $id !== $id) {
            throw new \InvalidArgumentException('A persisted positive module resource ID is required.');
        }
        $class = match ($module) {
            'game' => Instance::class,
            'db' => Database::class,
            'mail' => MailDomain::class,
            'voice' => $type === 'ts3' ? Ts3Instance::class : Ts6Instance::class,
            default => throw new \InvalidArgumentException('Unsupported managed backup module.'),
        };
        $resource = $this->entities->find($class, (int) $id);
        if ($resource === null) {
            throw new \RuntimeException('Managed backup resource does not exist.');
        }
        $binding = $this->bindings->find($module, $resourceId);
        $descriptor = ['module' => $module, 'resource_id' => $resourceId];
        $domainId = null;
        $mailDomainId = null;
        if ($resource instanceof MailDomain) {
            $customer = $resource->getOwner();
            $domainId = $resource->getDomain()->getId();
            $mailDomainId = $resource->getId();
            if ($binding === null || !isset($binding['path']) || (!isset($binding['service']) && !isset($binding['services'])) || !is_array($binding['additional_paths'] ?? null)
                || !isset($binding['additional_paths']['config'], $binding['additional_paths']['dkim'])) {
                throw new \RuntimeException('Mail full backups require an administrator binding: Agent, Maildir path, service, additional_paths.config and additional_paths.dkim.');
            }
            $agent = $this->entities->find(Agent::class, $binding['agent_id']);
            if (!$agent instanceof Agent) {
                throw new \RuntimeException('Mail backup binding Agent does not exist.');
            }
        } elseif ($resource instanceof Database) {
            $customer = $resource->getCustomer();
            $node = $resource->getNode();
            $password = $resource->getEncryptedPassword();
            if ($node === null || $password === null || !in_array($resource->getEngine(), ['mysql', 'mariadb'], true)) {
                throw new \RuntimeException('Database full backups require a managed MySQL/MariaDB node and encrypted database credentials.');
            }
            $agent = $node->getAgent();
            $descriptor['database'] = [
                'host' => $resource->getHost(), 'port' => $resource->getPort(), 'name' => $resource->getName(),
                'username' => $resource->getUsername(), 'password' => $this->encryption->decrypt($password),
                'tls_mode' => $this->databaseTlsMode($node->getTlsMode()), 'ca_cert' => $node->getCaCert(),
            ];
        } else {
            $customer = $resource->getCustomer();
            $agent = $resource->getNode();
            $descriptor['path'] = $resource->getInstallPath();
            if ($resource instanceof Ts3Instance && $resource->getDatabaseMode() === Ts3DatabaseMode::Mysql) {
                $password = $resource->getDatabasePassword();
                if ($password === null || $resource->getDatabaseName() === null || $resource->getDatabaseUsername() === null || $resource->getDatabaseHost() === null) {
                    throw new \RuntimeException('TS3 external database credentials are incomplete.');
                }
                $descriptor['database'] = [
                    'host' => $resource->getDatabaseHost(), 'port' => $resource->getDatabasePort() ?? 3306,
                    'name' => $resource->getDatabaseName(), 'username' => $resource->getDatabaseUsername(),
                    'password' => $this->encryption->decrypt($password), 'tls_mode' => 'disabled',
                ];
            }
        }
        if ($binding !== null) {
            if ($binding['agent_id'] !== $agent->getId()) {
                throw new \RuntimeException('Backup binding Agent differs from the resource management Agent.');
            }
            foreach (['path', 'service', 'services', 'additional_paths'] as $key) {
                if (isset($binding[$key])) {
                    $descriptor[$key] = $binding[$key];
                }
            }
        }
        if ($module !== 'db') {
            if (!is_string($descriptor['path'] ?? null) || ($descriptor['path'] ?? '') === '') {
                throw new \RuntimeException('Resource install path is missing; configure its administrator backup binding.');
            }
            // Running game/voice databases and mail stores require explicit quiescing.
            if ((!is_string($descriptor['service'] ?? null) || ($descriptor['service'] ?? '') === '') && (!is_array($descriptor['services'] ?? null) || ($descriptor['services'] ?? []) === [])) {
                throw new \RuntimeException('Full file backups require a configured service unit to quiesce writes.');
            }
            ModuleBackupBindingStore::validate(['agent_id' => $agent->getId()] + array_intersect_key($descriptor, array_flip(['path', 'service', 'services', 'additional_paths'])));
        }
        $customerId = $customer->getId();
        if ($customerId === null || $domainId === null && $module === 'mail') {
            throw new \RuntimeException('Backup resource ownership is not persisted.');
        }
        return new ManagedBackupResource($agent, (string) $customerId, $descriptor, $mailDomainId, $domainId);
    }

    private function databaseTlsMode(string $mode): string
    {
        return match ($mode) {
            'off' => 'disabled',
            'required' => 'required',
            'verify_ca' => 'verify_ca',
            'verify_full' => 'verify_identity',
            default => throw new \InvalidArgumentException('Unsupported database backup TLS mode.'),
        };
    }

}
