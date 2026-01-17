<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\SetupChecker;
use PHPUnit\Framework\TestCase;

final class SetupCheckerTest extends TestCase
{
    public function testReportsMissingRequirements(): void
    {
        $template = $this->buildTemplate();
        $template->setRequirementVars([
            [
                'key' => 'steam_user',
                'label' => 'Steam Username',
                'type' => 'text',
                'required' => true,
                'scope' => 'customer_allowed',
            ],
        ]);
        $template->setRequirementSecrets([
            [
                'key' => 'gslt',
                'label' => 'GSLT',
                'type' => 'password',
                'required' => true,
                'scope' => 'customer_allowed',
            ],
        ]);

        $instance = $this->buildInstance($template);
        $checker = new SetupChecker();

        $status = $checker->getSetupStatus($instance);

        self::assertFalse($status['is_ready']);
        self::assertCount(2, $status['missing']);
        self::assertSame([SetupChecker::ACTION_INSTALL, SetupChecker::ACTION_START, SetupChecker::ACTION_UPDATE], $status['blocked_actions']);
    }

    public function testMarksReadyWhenRequirementsPresent(): void
    {
        $template = $this->buildTemplate();
        $template->setRequirementVars([
            [
                'key' => 'steam_user',
                'label' => 'Steam Username',
                'type' => 'text',
                'required' => true,
                'scope' => 'customer_allowed',
            ],
        ]);
        $template->setRequirementSecrets([
            [
                'key' => 'gslt',
                'label' => 'GSLT',
                'type' => 'password',
                'required' => true,
                'scope' => 'customer_allowed',
            ],
        ]);

        $instance = $this->buildInstance($template);
        $instance->setSetupVars(['steam_user' => 'demo']);
        $instance->setSetupSecret('gslt', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);

        $checker = new SetupChecker();
        $status = $checker->getSetupStatus($instance);

        self::assertTrue($status['is_ready']);
        self::assertSame([], $status['missing']);
    }

    public function testCustomerRequirementsFilterScope(): void
    {
        $template = $this->buildTemplate();
        $template->setRequirementVars([
            [
                'key' => 'steam_user',
                'label' => 'Steam Username',
                'type' => 'text',
                'required' => true,
                'scope' => 'customer_allowed',
            ],
            [
                'key' => 'admin_note',
                'label' => 'Admin Note',
                'type' => 'text',
                'required' => true,
                'scope' => 'admin_only',
            ],
        ]);

        $checker = new SetupChecker();
        $requirements = $checker->getCustomerRequirements($template);

        self::assertCount(1, $requirements['vars']);
        self::assertSame('steam_user', $requirements['vars'][0]['key']);
    }

    private function buildTemplate(): Template
    {
        return new Template(
            'cs2',
            'CS2',
            null,
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
            ],
            '-game cs2',
            [],
            [],
            [],
            [],
            'install',
            'update',
            [],
            [],
            [],
            [],
            ['linux'],
            [
                [
                    'role' => 'game',
                    'protocol' => 'udp',
                    'count' => 1,
                    'required' => true,
                    'contiguous' => false,
                ],
            ],
            [
                'required_vars' => [],
                'required_secrets' => ['STEAM_GSLT'],
                'steam_install_mode' => 'anonymous',
                'customer_allowed_vars' => [],
                'customer_allowed_secrets' => ['STEAM_GSLT'],
            ],
        );
    }

    private function buildInstance(Template $template): Instance
    {
        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);

        return new Instance(
            $customer,
            $template,
            $agent,
            100,
            1024,
            10240,
            null,
            InstanceStatus::Stopped,
            InstanceUpdatePolicy::Manual,
        );
    }
}
