<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;

class MailNodeMetricsAggregator
{
    /** @var string[] */
    private const PORT_KEYS = ['25', '465', '587', '110', '143', '993', '995'];

    /** @param MailNode[] $mailNodes @param MailDomain[] $mailDomains */
    public function aggregate(array $mailNodes, array $mailDomains): array
    {
        $agentsByMailNode = $this->mapAgentsByMailNode($mailDomains);
        $nodes = [];
        foreach ($mailNodes as $mailNode) {
            $agent = $agentsByMailNode[spl_object_id($mailNode)] ?? null;
            $nodes[] = $this->buildNodeMetrics($mailNode, $agent);
        }

        return ['nodes' => $nodes];
    }

    private function buildNodeMetrics(MailNode $mailNode, ?Agent $agent): array
    {
        $stats = $agent?->getLastHeartbeatStats();
        $mail = is_array($stats) && is_array($stats['mail'] ?? null) ? $stats['mail'] : [];
        $warnings = is_array($stats) && is_array($stats['mail_warnings'] ?? null) ? array_values(array_filter($stats['mail_warnings'], 'is_string')) : [];

        return [
            'id' => $mailNode->getId(),
            'name' => $mailNode->getName(),
            'agent_id' => $agent?->getId(),
            'online' => $agent?->getStatus() === Agent::STATUS_ACTIVE,
            'last_seen_at' => $agent?->getLastSeenAt()?->format(DATE_ATOM),
            'metrics' => $this->sanitizeMetrics($mail),
            'warnings' => $warnings,
        ];
    }

    private function sanitizeMetrics(array $mail): array
    {
        $ports = is_array($mail['ports'] ?? null) ? $mail['ports'] : [];

        return [
            'postfix_active' => (bool) ($mail['postfix_active'] ?? false),
            'dovecot_active' => (bool) ($mail['dovecot_active'] ?? false),
            'queue_total' => $this->toInt($mail['queue_total'] ?? 0),
            'queue_deferred' => $this->toInt($mail['queue_deferred'] ?? 0),
            'queue_hold' => $this->toInt($mail['queue_hold'] ?? 0),
            'mailbox_count' => $this->toInt($mail['mailbox_count'] ?? 0),
            'domain_count' => $this->toInt($mail['domain_count'] ?? 0),
            'alias_count' => $this->toInt($mail['alias_count'] ?? 0),
            'maildir_disk_bytes' => $this->toInt($mail['maildir_disk_bytes'] ?? 0),
            'mailbox_usage' => $this->normalizeMailboxUsage(is_array($mail['mailbox_usage'] ?? null) ? $mail['mailbox_usage'] : []),
            'mailbox_usage_truncated' => (bool) ($mail['mailbox_usage_truncated'] ?? false),
            'ports' => $this->normalizePorts($ports),
        ];
    }


    private function normalizeMailboxUsage(array $usage): array
    {
        $normalized = [];
        ksort($usage);
        foreach ($usage as $address => $values) {
            if (!is_string($address) || !str_contains($address, '@')) {
                continue;
            }
            $usedBytes = 0;
            if (is_array($values) && array_key_exists('used_bytes', $values)) {
                $usedBytes = max(0, $this->toInt($values['used_bytes']));
            }
            $normalized[strtolower($address)] = ['used_bytes' => $usedBytes];
        }

        return $normalized;
    }

    private function normalizePorts(array $ports): array
    {
        $normalized = [];
        foreach (self::PORT_KEYS as $port) {
            $normalized[$port] = (bool) ($ports[$port] ?? false);
        }
        return $normalized;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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

