<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Application\AgentEndpointResolver;
use App\Module\Nodes\Application\Console\VmConsoleBroker;
use App\Module\Nodes\Application\Kvm\KvmPanelService;
use PHPUnit\Framework\TestCase;

final class VmConsoleReadinessTest extends TestCase
{
    private function broker(?string $secret = null): VmConsoleBroker
    {
        // Readiness depends only on configuration and the existing node status;
        // it must not perform provisioning, write jobs or contact a VM.
        $broker = (new \ReflectionClass(VmConsoleBroker::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(VmConsoleBroker::class, 'gatewaySecret'))->setValue($broker, $secret);
        $kvm = (new \ReflectionClass(KvmPanelService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(VmConsoleBroker::class, 'kvm'))->setValue($broker, $kvm);
        (new \ReflectionProperty(VmConsoleBroker::class, 'endpoints'))->setValue($broker, new AgentEndpointResolver());

        return $broker;
    }

    private function node(int $version = 1): Agent
    {
        $node = new Agent('console-node', ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);
        $node->recordHeartbeat([], 'test', '127.0.0.1', [], ['kvm_managed' => ['supported' => true, 'version' => 1, 'console_version' => $version]], 'active');

        return $node;
    }

    public function testMissingGatewayIsDistinctFromAnOldAgentOrAStoppedVm(): void
    {
        self::assertSame('console.not_configured', $this->broker()->unavailableReason(['status' => 'running']));
        $broker = $this->broker(str_repeat('s', 64));
        self::assertSame('console.agent_update', $broker->unavailableReason(['state' => 'running'], $this->node(0)));
        self::assertSame('console.vm_stopped', $broker->unavailableReason(['status' => 'stopped']));
        self::assertSame('console.prepare_required', $broker->unavailableReason(['state' => 'running', 'console_enabled' => false], $this->node()));
        self::assertSame('console.vm_unavailable', $broker->unavailableReason(null));
        self::assertSame('console.vm_unavailable', $broker->unavailableReason(['status' => 'running', 'template' => 1]));
    }

    public function testOnlyRunningAvailableGuestsAreAdvertisedAsReady(): void
    {
        $broker = $this->broker(str_repeat('s', 64));
        self::assertNull($broker->unavailableReason(['status' => 'running']));
        self::assertNull($broker->unavailableReason(['state' => 'running', 'console_enabled' => true], $this->node()));
        $node = $this->node();
        $node->setStatus(Agent::STATUS_DECOMMISSIONED);
        self::assertSame('console.node_unavailable', $broker->unavailableReason(['state' => 'running'], $node));
    }

    public function testRemotePlaintextAgentIsReportedWithoutDisablingTransportChecks(): void
    {
        $broker = $this->broker(str_repeat('s', 64));
        $node = $this->node();
        $node->setServiceBaseUrl('http://192.168.178.42:7456');
        self::assertSame('console.transport_required', $broker->unavailableReason(['state' => 'running'], $node));
        $node->setServiceBaseUrl('HTTPS://node.example.test:7456');
        self::assertTrue($broker->kvmTransportAvailable($node));
        $node->setServiceBaseUrl('http://127.0.0.1:7456');
        self::assertTrue($broker->kvmTransportAvailable($node));
    }
}
