<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\MailPolicy;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class MailPolicyTest extends TestCase
{
    public function testApplyNormalizesBoundsAndSpamLevel(): void
    {
        $policy = new MailPolicy($this->createDomain());

        $policy->apply(true, 0, -4, false, 'invalid', true);

        self::assertTrue($policy->isRequireTls());
        self::assertSame(1, $policy->getMaxRecipients());
        self::assertSame(1, $policy->getMaxHourlyEmails());
        self::assertSame(MailPolicy::SPAM_MED, $policy->getSpamProtectionLevel());
    }

    private function createDomain(): Domain
    {
        $owner = new User('owner@example.test', UserType::Customer);
        $agent = new Agent('node-policy', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $webspace = new Webspace($owner, $agent, '/srv/www/ws-policy', 'public', 'example.com', '8.4', 1024);

        return new Domain($owner, $webspace, 'example.com');
    }
}
