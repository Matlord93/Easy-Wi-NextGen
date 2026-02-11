<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class UserRolesBridgeTest extends TestCase
{
    public function testAdminRolesRemainAdminScoped(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);
        $superadmin = new User('superadmin@example.test', UserType::Superadmin);

        self::assertSame(['ROLE_ADMIN'], $admin->getRoles());
        self::assertSame(['ROLE_SUPERADMIN', 'ROLE_ADMIN'], $superadmin->getRoles());
    }

    public function testCustomerTypeDefaultsToRoleUserWithoutRoleCustomer(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);

        self::assertContains('ROLE_USER', $customer->getRoles());
        self::assertNotContains('ROLE_CUSTOMER', $customer->getRoles());
        self::assertNotContains('ROLE_MEMBER', $customer->getRoles());
    }

    public function testMemberFlagAddsRoleMember(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $customer->setMemberAccessEnabled(true);

        self::assertContains('ROLE_MEMBER', $customer->getRoles());
        self::assertContains('ROLE_USER', $customer->getRoles());
    }

    public function testCustomerFlagAddsRoleCustomerOnlyWhenEnabled(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $customer->setCustomerAccessEnabled(true);

        self::assertContains('ROLE_CUSTOMER', $customer->getRoles());
        self::assertContains('ROLE_USER', $customer->getRoles());
    }

    public function testResellerGetsRoleUserButNotRoleCustomerByDefault(): void
    {
        $reseller = new User('reseller@example.test', UserType::Reseller);

        self::assertContains('ROLE_RESELLER', $reseller->getRoles());
        self::assertContains('ROLE_USER', $reseller->getRoles());
        self::assertNotContains('ROLE_CUSTOMER', $reseller->getRoles());
    }
}
