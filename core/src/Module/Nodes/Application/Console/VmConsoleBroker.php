<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Console;

use App\Repository\QueryParameter;
use App\Module\Core\Application\AgentCredentialsResolver;
use App\Module\Core\Application\AgentEndpointResolverInterface;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Nodes\Application\Kvm\KvmPanelService;
use App\Module\Nodes\Application\Proxmox\ProxmoxApiClient;
use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\VmConsoleSession;
use App\Repository\UserSessionRepository;
use App\Security\SessionAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final readonly class VmConsoleBroker
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProxmoxManager $proxmox,
        private ProxmoxApiClient $api,
        private KvmPanelService $kvm,
        private SecretsCryptoInterface $crypto,
        private AgentEndpointResolverInterface $endpoints,
        private AgentCredentialsResolver $credentials,
        private UserSessionRepository $sessions,
        private AuditLoggerInterface $audit,
        #[Autowire('%env(default::VM_CONSOLE_GATEWAY_SECRET)%')] private ?string $gatewaySecret = null,
    ) {
    }

    public function configured(): bool
    {
        return strlen($this->gatewaySecret ?? '') >= 32;
    }

    public function unavailableReason(?array $guest, ?Agent $node = null): ?string
    {
        if (!$this->configured()) {
            return 'console.not_configured';
        }
        if ($node && 1 !== ($node->getMetadata()['kvm_managed']['console_version'] ?? null)) {
            return 'console.agent_update';
        }
        if ($node && !in_array($this->kvm->availability($node), [null, '', '0'], true)) {
            return 'console.node_unavailable';
        }
        if ($node && !$this->kvmTransportAvailable($node)) {
            return 'console.transport_required';
        }
        if ($guest === null || $guest === [] || !empty($guest['template'])) {
            return 'console.vm_unavailable';
        }
        if ('running' !== ($guest['state'] ?? $guest['status'] ?? null)) {
            return 'console.vm_stopped';
        }
        if ($node && false === ($guest['console_enabled'] ?? null)) {
            return 'console.prepare_required';
        }

        return null;
    }

    public function kvmTransportAvailable(Agent $node): bool
    {
        try {
            $this->kvmConsoleBase($node);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function kvmConsoleBase(Agent $node): string
    {
        $base = rtrim($this->endpoints->resolveForAgent($node)->getBaseUrl(), '/');
        $parts = parse_url($base);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (!is_array($parts) || in_array($host, ['', '0'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ('https' !== $scheme && !('http' === $scheme && in_array($host, ['127.0.0.1', '[::1]', '::1'], true)))) {
            throw new \RuntimeException('KVM console requires HTTPS or loopback HTTP.');
        }

        $separator = strpos($base, ':');
        if ($separator === false) {
            throw new \RuntimeException('KVM console URL has no scheme separator.');
        }

        return $scheme.substr($base, $separator);
    }

    public function assertGateway(Request $request): void
    {
        if (null === $this->gatewaySecret || !$this->configured() || !hash_equals($this->gatewaySecret, $request->headers->get('X-EasyWI-Console-Gateway', ''))) {
            throw new AccessDeniedHttpException();
        }
    }

    public function assignment(string $provider, int $id, User $actor): KvmGuestAssignment|ProxmoxGuestAssignment
    {
        $class = match ($provider) {
            'proxmox' => ProxmoxGuestAssignment::class, 'kvm' => KvmGuestAssignment::class, default => throw new NotFoundHttpException()
        };
        $assignment = $this->em->find($class, $id);
        if (!$assignment || !$actor->isCustomer() || $assignment->getCustomer()->getId() !== $actor->getId()) {
            throw new NotFoundHttpException();
        }

        return $assignment;
    }

    /** Only a safe subset of the host inventory is available to customers. */
    public function kvmGuest(KvmGuestAssignment $assignment): ?array
    {
        foreach ($this->kvm->inventory($assignment->getNode())['guests'] ?? [] as $guest) {
            if (($guest['id'] ?? '') === $assignment->getVmUuid() && ($guest['managed'] ?? false)) {
                return array_intersect_key($guest, array_flip(['id', 'label', 'state', 'vcpus', 'memory_mib', 'disk_gib', 'console_enabled']));
            }
        }

        return null;
    }

    /** @return array{ticket: string, websocket_path: string, expires_in: int} */
    public function mint(string $provider, int $id, User $actor, Request $request): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Console gateway is not configured.');
        }
        $assignment = $this->assignment($provider, $id, $actor);
        $this->assertAssignmentAvailable($assignment, $actor);
        $sessionHash = $this->sessionHash($request, $actor);
        $recent = $this->em->getRepository(VmConsoleSession::class)->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.customer = :actor')->andWhere('s.createdAt > :cutoff')->setParameter('actor', QueryParameter::entityId($actor->getId(), \App\Module\Core\Domain\Entity\User::class))->setParameter('cutoff', new \DateTimeImmutable('-1 minute'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->getQuery()->getSingleScalarResult();
        if ((int) $recent >= 5) {
            throw new TooManyRequestsHttpException(60);
        }
        $ticket = bin2hex(random_bytes(32));
        $grant = new VmConsoleSession($actor, $provider, $id, $sessionHash, hash('sha256', $ticket));
        $this->em->persist($grant);
        $this->audit->log($actor, 'vm.console_requested', ['provider' => $provider, 'assignment_id' => $id, 'session_id' => $grant->getId()]);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM vm_console_sessions WHERE expires_at < ?', [(new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);

        return ['ticket' => $ticket, 'websocket_path' => '/vm-console/ws', 'expires_in' => 45];
    }

    /** The target and credentials may only be returned to the trusted gateway. */
    public function connect(string $ticket, User $actor, Request $request): array
    {
        $this->assertGateway($request);
        if (!preg_match('/^[a-f0-9]{64}$/D', $ticket)) {
            throw new AccessDeniedHttpException();
        }
        $grant = $this->em->getRepository(VmConsoleSession::class)->findOneBy(['ticketHash' => hash('sha256', $ticket)]);
        if (!$grant || $grant->getClaimedAt() || $grant->getConnectBefore() <= new \DateTimeImmutable()) {
            throw new AccessDeniedHttpException();
        }
        $assignment = $this->validateGrant($grant, $actor, $request);
        $claimed = $this->em->getConnection()->executeStatement('UPDATE vm_console_sessions SET claimed_at = ? WHERE id = ? AND claimed_at IS NULL AND connect_before > ?', [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $grant->getId(), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        if (1 !== $claimed) {
            throw new AccessDeniedHttpException();
        }
        // Target resolution happens after the atomic claim. Failures consume the
        // ticket; reconnecting always requests a fresh, single-use grant.
        if ($assignment instanceof ProxmoxGuestAssignment) {
            $guest = $this->proxmox->assignedGuest($assignment, $actor);
            $connection = $assignment->getCluster()->connection($this->crypto);
            $node = (string) $guest['node'];
            $vnc = $this->api->vncProxy($connection, $node, $assignment->getVmId(), $assignment->getKind());
            $url = (str_starts_with($connection->baseUrl, 'https:') ? 'wss:'.substr(rtrim($connection->baseUrl, '/'), 6) : rtrim($connection->baseUrl, '/')).'/api2/json/nodes/'.rawurlencode($node).'/'.$assignment->getKind().'/'.$assignment->getVmId().'/vncwebsocket?'.http_build_query(['port' => $vnc['port'], 'vncticket' => $vnc['ticket']], '', '&', PHP_QUERY_RFC3986);

            return ['session_id' => $grant->getId(), 'expires_at' => $grant->getExpiresAt()->getTimestamp(), 'url' => $url, 'authorization' => 'PVEAPIToken='.$connection->tokenId.'='.$connection->tokenSecret, 'password' => $vnc['ticket'], 'skip_tls_verify' => !$connection->verifyTls];
        }
        $base = $this->kvmConsoleBase($assignment->getNode());
        $url = ('ws'.substr($base, 4)).'/v1/kvm/console/'.$assignment->getVmUuid();

        return ['session_id' => $grant->getId(), 'expires_at' => $grant->getExpiresAt()->getTimestamp(), 'url' => $url, 'authorization' => 'Bearer '.$this->credentials->resolveForAgent($assignment->getNode())->getSecret(), 'password' => ''];
    }

    public function validate(string $id, User $actor, Request $request): void
    {
        $this->assertGateway($request);
        $this->em->clear(); // Always observe reassignment/revocation from the DB.
        $grant = $this->em->find(VmConsoleSession::class, $id);
        if (!$grant || !$grant->getClaimedAt()) {
            throw new AccessDeniedHttpException();
        }
        $this->validateGrant($grant, $actor, $request);
    }

    private function validateGrant(VmConsoleSession $grant, User $actor, Request $request): KvmGuestAssignment|ProxmoxGuestAssignment
    {
        if ($grant->getCustomer()->getId() !== $actor->getId() || $grant->getExpiresAt() <= new \DateTimeImmutable() || !hash_equals($grant->getSessionHash(), $this->sessionHash($request, $actor))) {
            throw new AccessDeniedHttpException();
        }
        $assignment = $this->assignment($grant->getProvider(), $grant->getAssignmentId(), $actor);
        $this->assertAssignmentAvailable($assignment, $actor);

        return $assignment;
    }

    private function assertAssignmentAvailable(KvmGuestAssignment|ProxmoxGuestAssignment $assignment, User $actor): void
    {
        if ($assignment instanceof ProxmoxGuestAssignment) {
            if (!$assignment->getCluster()->isEnabled()) {
                throw new AccessDeniedHttpException();
            }
            $guest = $this->proxmox->assignedGuest($assignment, $actor);
            $config = $this->api->guestConfig($assignment->getCluster()->connection($this->crypto), (string) $guest['node'], $assignment->getVmId(), $assignment->getKind());
            if (!in_array($assignment->getIdentityTag(), explode(';', (string) ($config['tags'] ?? '')), true)) {
                throw new AccessDeniedHttpException();
            }
            if (!empty($guest['template']) || ($guest['status'] ?? '') !== 'running') {
                throw new \RuntimeException('VM console requires a running guest.');
            }

            return;
        }
        if (1 !== ($assignment->getNode()->getMetadata()['kvm_managed']['console_version'] ?? null) || null !== $this->kvm->availability($assignment->getNode())) {
            throw new \RuntimeException('KVM node unavailable.');
        }
        // Fresh validation of the domain and Unix socket is performed by the
        // agent when the gateway opens the actual stream.
    }

    private function sessionHash(Request $request, User $actor): string
    {
        foreach ([SessionAuthenticator::CUSTOMER_SESSION_COOKIE, SessionAuthenticator::ADMIN_SESSION_COOKIE] as $cookie) {
            $token = $request->cookies->get($cookie);
            if (!is_string($token) || '' === $token) {
                continue;
            }
            $hash = hash('sha256', $token);
            $session = $this->sessions->findActiveByTokenHash($hash);
            if ($session && $session->getUser()->getId() === $actor->getId() && $session->getCredentialsVersion() === $actor->getCredentialsVersion()) {
                return $hash;
            }
        }
        throw new AccessDeniedHttpException();
    }
}
