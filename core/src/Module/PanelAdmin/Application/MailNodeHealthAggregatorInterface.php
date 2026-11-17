<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;

/**
 * @psalm-type HealthNode = array{
 *     id: int|null, name: string, agent_id: string|null, online: bool, overall_ok: bool,
 *     last_seen_at: string|null, checks: array<string, array<array-key, mixed>>,
 *     critical_failures: list<string>, warnings: list<string>, mail_report: array<array-key, mixed>|null
 * }
 */
interface MailNodeHealthAggregatorInterface
{
    /**
     * @param MailNode[] $mailNodes
     * @param MailDomain[] $mailDomains
     * @return array{nodes: list<HealthNode>}
     */
    public function aggregate(array $mailNodes, array $mailDomains): array;

    /** @return array<array-key, mixed>|null */
    public function fetchAgentMailReport(Agent $agent, bool $refresh): ?array;
}
