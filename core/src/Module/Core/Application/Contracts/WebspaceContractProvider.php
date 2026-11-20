<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Repository\WebspaceNodeRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WebspaceContractProvider implements ContractResourceProvider
{
    public function __construct(private EntityManagerInterface $em, private WebspaceNodeRepository $nodes, private ContractJobs $jobs)
    {
    }

    #[\Override]
    public function kinds(): array
    {
        return [ShopProductKind::Webspace];
    }

    #[\Override]
    public function provision(ShopContract $contract): void
    {
        $c = $contract->getConfiguration();
        $node = $this->em->find(Agent::class, (string) $c['node_id']);
        if (null === $node) {
            throw new \DomainException('WEB_NODE_MISSING');
        }
        $hostname = (string) $c['hostname'];
        $existing = $this->em->getRepository(Domain::class)->findOneBy(['name' => $hostname]);
        if (null !== $existing) {
            throw new \DomainException('DOMAIN_ALREADY_IN_USE');
        }
        $path = '/var/www/'.$hostname;
        $web = new Webspace($contract->getCustomer(), $node, $path, $path.'/public', $hostname, (string) ($c['php_version'] ?? '8.4'), (int) $c['disk_mib'], false, null, Webspace::STATUS_ACTIVE, (int) $c['disk'] * 1024 * 1024 * 1024, false, true);
        $this->em->persist($web);
        $this->em->flush();
        $web->setSystemUsername('ws'.($web->getId() ?? 0));
        $domain = new Domain($contract->getCustomer(), $web, $hostname);
        $this->em->persist($domain);
        $job = new Job('webspace.create', array_replace($this->payload($web), ['domain' => $hostname, 'ddos_protection_enabled' => false, 'assigned_port' => null, 'owner_user' => $web->getSystemUsername(), 'owner_group' => $web->getSystemUsername(), 'php_version' => $web->getPhpVersion(), 'php_fpm_pool_path' => '/etc/easywi/web/php-fpm/'.$web->getSystemUsername().'.conf', 'pool_name' => $web->getSystemUsername(), 'nginx_include_path' => '/etc/easywi/web/nginx/includes/'.$web->getSystemUsername().'.conf']));
        $this->em->persist($job);
        $contract->bind((string) $web->getId(), $job->getId(), 'core');
    }

    #[\Override]
    public function readyAt(ShopContract $contract): ?\DateTimeImmutable
    {
        $at = $this->jobs->readyAt($contract);
        if (null === $at) {
            return null;
        }
        $web = $this->em->find(Webspace::class, (int) $contract->getResourceId());
        if (null === $web) {
            return null;
        }
        if ([] === $contract->getAuxiliaryJobs()) {
            $ids = [];
            foreach ($this->em->getRepository(Domain::class)->findBy(['webspace' => $web]) as $domain) {
                $job = new Job('webspace.domain.apply', array_replace($this->payload($web), ['domain_id' => (string) $domain->getId(), 'domain' => $domain->getName(), 'nginx_vhost_path' => '/etc/easywi/web/nginx/vhosts/'.$domain->getName().'.conf', 'target_path' => '', 'redirect_https' => '0', 'redirect_www' => '0']));
                $this->em->persist($job);
                $ids[] = $job->getId();
            }
            $contract->auxiliaryJobs($ids);

            return null;
        }
        foreach ($contract->getAuxiliaryJobs() as $id) {
            $job = $this->em->find(Job::class, $id);
            if (null === $job || JobStatus::Succeeded !== $job->getStatus()) {
                if (JobStatus::Failed === $job?->getStatus()) {
                    $contract->fail('WEB_DOMAIN_APPLY_FAILED');
                }

                return null;
            } $at = max($at, $job->getUpdatedAt());
        }

        return $at;
    }

    #[\Override]
    public function stop(ShopContract $contract): bool
    {
        $web = $this->em->find(Webspace::class, (int) $contract->getResourceId());
        if (null === $web) {
            return true;
        } $web->setStatus(Webspace::STATUS_SUSPENDED);
        if ([] !== $contract->getStopJobs()) {
            foreach ($contract->getStopJobs() as $id) {
                if (JobStatus::Succeeded !== $this->em->find(Job::class, $id)?->getStatus()) {
                    return false;
                }
            }

            return true;
        }
        if (null === $this->jobs->readyAt($contract) && 'PROVISION_FAILED' !== $contract->getError()) {
            return false;
        }
        foreach ($contract->getAuxiliaryJobs() as $id) {
            $job = $this->em->find(Job::class, $id);
            if (null !== $job && !$job->getStatus()->isTerminal()) {
                return false;
            }
        }
        $ids = [];
        foreach ($this->em->getRepository(Domain::class)->findBy(['webspace' => $web]) as $domain) {
            $job = new Job('webspace.domain.apply', array_replace($this->payload($web), ['domain_id' => (string) $domain->getId(), 'domain' => $domain->getName(), 'nginx_vhost_path' => '/etc/easywi/web/nginx/vhosts/'.$domain->getName().'.conf', 'action' => 'remove']));
            $this->em->persist($job);
            $ids[] = $job->getId();
        }
        $contract->stopJobs($ids);

        return [] === $ids;
    }

    /** @return array<string,int|string|bool|null> */
    private function payload(Webspace $web): array
    {
        $node = $this->nodes->findByAgent($web->getNode());

        return ['agent_id' => $web->getNode()->getId(), 'webspace_id' => (string) $web->getId(), 'web_root' => $web->getPath(), 'docroot' => $web->getDocroot(), 'runtime' => $web->getRuntime(), 'php_fpm_listen' => '/run/easywi/php-fpm/'.$web->getSystemUsername().'.sock', 'protected_vhost_paths' => $node?->isPanelHost() ? ($node->getPanelVhostPath() ?? '') : ''];
    }
}
