<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class DomainCapabilitiesTest extends TestCase
{
    public function testDomainWithoutWebspaceStartsWithWebspaceCapabilityDisabled(): void
    {
        $owner = new User('owner@example.test', UserType::Customer);
        $domain = new Domain($owner, null, 'example.test');

        self::assertNull($domain->getWebspace());
        self::assertFalse($domain->hasWebspaceCapability());
        self::assertFalse($domain->hasMailCapability());
    }

    public function testSettingCapabilitiesCanDetachWebspaceAndEnableMail(): void
    {
        $owner = new User('owner@example.test', UserType::Customer);
        $agent = new Agent('node-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'web-node-1');
        $webspace = new Webspace($owner, $agent, '/srv/www/ws-01', 'public', 'example.test', '8.4', 1024);
        $domain = new Domain($owner, $webspace, 'example.test');

        $domain->setCapabilities(false, true);

        self::assertNull($domain->getWebspace());
        self::assertFalse($domain->hasWebspaceCapability());
        self::assertTrue($domain->hasMailCapability());
    }
}
