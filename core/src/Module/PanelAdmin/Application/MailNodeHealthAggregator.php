<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MailNodeHealthAggregator
{
    /** @var string[] */
    private const REQUIRED_KEYS = [
        'postfix_installed', 'dovecot_installed', 'postmap_available', 'postfix_active', 'dovecot_active',
        'postfix_virtual_mailboxes_file', 'postfix_virtual_domains_file', 'postfix_virtual_aliases_file',
        'dovecot_users_file', 'maildir_writable', 'port_25_listening', 'port_465_listening',
        'port_587_listening', 'port_110_listening', 'port_143_listening', 'port_993_listening', 'port_995_listening',
    ];

    /** @var string[] */
    private const CRITICAL_KEYS = [
        'postfix_installed', 'dovecot_installed', 'postmap_available', 'postfix_active', 'dovecot_active',
        'postfix_virtual_mailboxes_file', 'dovecot_users_file', 'maildir_writable',
        'port_25_listening', 'port_587_listening', 'port_993_listening',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AgentEndpointResolver $endpointResolver,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param MailNode[] $mailNodes @param MailDomain[] $mailDomains */
    public function aggregate(array $mailNodes, array $mailDomains): array
    {
        $agentsByMailNode = $this->mapAgentsByMailNode($mailDomains);
        $nodes = [];
        foreach ($mailNodes as $mailNode) {
            $agent = $agentsByMailNode[spl_object_id($mailNode)] ?? null;
            $nodes[] = $this->buildNodeHealth($mailNode, $agent);
        }

        return ['nodes' => $nodes];
    }

    private function buildNodeHealth(MailNode $mailNode, ?Agent $agent): array
    {
        $checks = [];
        $warnings = [];
        $criticalFailures = [];

        if (!$agent instanceof Agent) {
            $checks = $this->fallbackMissingChecks('no agent mapped to this mail node');
            $criticalFailures = self::CRITICAL_KEYS;
            $warnings[] = 'mail node has no resolvable agent mapping';
            return $this->nodePayload($mailNode, null, false, false, null, $checks, $criticalFailures, $warnings);
        }

        $online = $agent->getStatus() === Agent::STATUS_ACTIVE;
        $agentChecks = $this->fetchAgentChecks($agent);
        foreach (self::REQUIRED_KEYS as $key) {
            $checks[$key] = $agentChecks[$key] ?? ['ok' => false, 'message' => 'missing check from agent'];
        }

        foreach (self::CRITICAL_KEYS as $key) {
            if (($checks[$key]['ok'] ?? false) !== true) {
                $criticalFailures[] = $key;
            }
        }

        foreach (array_diff(self::REQUIRED_KEYS, self::CRITICAL_KEYS) as $key) {
            if (($checks[$key]['ok'] ?? false) !== true) {
                $warnings[] = $key;
            }
        }

        $overallOk = $online && $criticalFailures === [];

        return $this->nodePayload($mailNode, $agent, $online, $overallOk, $agent->getLastSeenAt(), $checks, $criticalFailures, $warnings);
    }

    private function nodePayload(MailNode $mailNode, ?Agent $agent, bool $online, bool $overallOk, ?\DateTimeImmutable $lastSeenAt, array $checks, array $criticalFailures, array $warnings): array
    {
        return [
            'id' => $mailNode->getId(),
            'name' => $mailNode->getName(),
            'agent_id' => $agent?->getId(),
            'online' => $online,
            'overall_ok' => $overallOk,
            'last_seen_at' => $lastSeenAt?->format(DATE_ATOM),
            'checks' => $checks,
            'critical_failures' => $criticalFailures,
            'warnings' => $warnings,
        ];
    }

    private function fetchAgentChecks(Agent $agent): array
    {
        try {
            $endpoint = $this->endpointResolver->resolveForAgent($agent)->getBaseUrl();
            $token = $agent->getServiceApiToken($this->secretsCrypto);
            $headers = ['Accept' => 'application/json'];
            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
            $response = $this->httpClient->request('GET', rtrim($endpoint, '/') . '/v1/mail/health', ['headers' => $headers, 'timeout' => 8]);
            $payload = json_decode($response->getContent(false), true);
            if (!is_array($payload)) {
                return [];
            }
            $checks = $payload['checks'] ?? [];
            return is_array($checks) ? $checks : [];
        } catch (\Throwable|ExceptionInterface $exception) {
            $this->logger->warning('Failed to fetch mail health from agent.', ['agent_id' => $agent->getId(), 'error' => $exception->getMessage()]);
            return [];
        }
    }

    private function fallbackMissingChecks(string $message): array
    {
        $checks = [];
        foreach (self::REQUIRED_KEYS as $key) {
            $checks[$key] = ['ok' => false, 'message' => $message];
        }
        return $checks;
    }

    /** @param MailDomain[] $mailDomains @return array<int,Agent> */
    private function mapAgentsByMailNode(array $mailDomains): array
    {
        $map = [];
        foreach ($mailDomains as $mailDomain) {
            $mailNode = $mailDomain->getNode();
            $webspace = $mailDomain->getDomain()->getWebspace();
            if ($webspace === null) {
                continue;
            }
            $map[spl_object_id($mailNode)] = $webspace->getNode();
        }
        return $map;
    }
}

