<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\PanelAdmin\Application\MailNodeMetricsAggregator;
use PHPUnit\Framework\TestCase;

final class MailNodeMetricsAggregatorTest extends TestCase
{
    public function testDefaultsWhenMetricsMissing(): void
    {
        $mailNode = new MailNode('mail-a', 'imap.example.com', 993, 'smtp.example.com', 587, null);
        $result = (new MailNodeMetricsAggregator())->aggregate([$mailNode], []);
        $node = $result['nodes'][0];

        self::assertSame(0, $node['metrics']['queue_total']);
        self::assertFalse($node['metrics']['postfix_active']);
        self::assertFalse($node['metrics']['ports']['25']);
        self::assertSame([], $node['warnings']);
    }

    public function testNormalizesMetricsPortsWarningsAndPrivacy(): void
    {
        $mailNode = new MailNode('mail-a', 'imap.example.com', 993, 'smtp.example.com', 587, null);
        $agent = new Agent('agent-1', ['key_id' => 'a', 'nonce' => 'b', 'ciphertext' => 'c']);
        $agent->setStatus(Agent::STATUS_ACTIVE);
        $agent->recordHeartbeat([
            'mail' => [
                'postfix_active' => true,
                'dovecot_active' => true,
                'queue_total' => 12,
                'queue_deferred' => 3,
                'queue_hold' => 1,
                'mailbox_count' => 25,
                'domain_count' => 4,
                'alias_count' => 10,
                'maildir_disk_bytes' => 123,
                'ports' => ['25' => true, '587' => true, '993' => true],
                'mailbox_usage' => ['U@example.com' => ['used_bytes' => -5], 'two@example.com' => []],
                'mailbox_usage_truncated' => true,
                'subject' => 'secret',
                'sender' => 'hidden@example.com',
            ],
            'mail_warnings' => ['ok'],
        ], '1.0.0', null, ['mail'], null, Agent::STATUS_ACTIVE);

        $domain = $this->createMock(\App\Module\Core\Domain\Entity\Domain::class);
        $webspace = $this->createMock(\App\Module\Core\Domain\Entity\Webspace::class);
        $mailDomain = $this->createMock(MailDomain::class);
        $mailDomain->method('getNode')->willReturn($mailNode);
        $mailDomain->method('getDomain')->willReturn($domain);
        $domain->method('getWebspace')->willReturn($webspace);
        $webspace->method('getNode')->willReturn($agent);

        $node = (new MailNodeMetricsAggregator())->aggregate([$mailNode], [$mailDomain])['nodes'][0];
        self::assertSame(12, $node['metrics']['queue_total']);
        self::assertFalse($node['metrics']['ports']['465']);
        self::assertSame(['ok'], $node['warnings']);
        self::assertSame(0, $node['metrics']['mailbox_usage']['u@example.com']['used_bytes']);
        self::assertSame(0, $node['metrics']['mailbox_usage']['two@example.com']['used_bytes']);
        self::assertTrue($node['metrics']['mailbox_usage_truncated']);
        self::assertArrayNotHasKey('subject', $node['metrics']);
        self::assertArrayNotHasKey('sender', $node['metrics']);
    }
}
