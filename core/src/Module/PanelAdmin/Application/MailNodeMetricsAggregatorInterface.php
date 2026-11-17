<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;

/**
 * @psalm-type MailMetrics = array{
 *     postfix_active: bool, dovecot_active: bool, queue_total: int, queue_deferred: int,
 *     queue_hold: int, mailbox_count: int, domain_count: int, alias_count: int,
 *     maildir_disk_bytes: int, mailbox_usage: array<string, array{used_bytes: int}>,
 *     mailbox_usage_truncated: bool,
 *     ports: array{25: bool, 465: bool, 587: bool, 110: bool, 143: bool, 993: bool, 995: bool}
 * }
 * @psalm-type MetricsNode = array{
 *     id: int|null, name: string, agent_id: string|null, online: bool,
 *     last_seen_at: string|null, metrics: MailMetrics, warnings: list<string>
 * }
 */
interface MailNodeMetricsAggregatorInterface
{
    /**
     * @param MailNode[] $mailNodes
     * @param MailDomain[] $mailDomains
     * @return array{nodes: list<MetricsNode>}
     */
    public function aggregate(array $mailNodes, array $mailDomains): array;
}
