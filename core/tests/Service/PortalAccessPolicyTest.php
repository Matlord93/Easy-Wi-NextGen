<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Enum\UserType;
use App\Security\PortalAccessPolicy;
use PHPUnit\Framework\TestCase;

final class PortalAccessPolicyTest extends TestCase
{
    public function testAdminPathsOnlyAllowAdmins(): void
    {
        $policy = new PortalAccessPolicy();

        $admin = new User('admin@example.test', UserType::Admin);
        $reseller = new User('reseller@example.test', UserType::Reseller);
        $customer = new User('customer@example.test', UserType::Customer);

        self::assertTrue($policy->isAllowed($admin, '/admin'));
        self::assertFalse($policy->isAllowed($reseller, '/admin'));
        self::assertFalse($policy->isAllowed($customer, '/admin/users'));
        self::assertTrue($policy->isAllowed($admin, '/api/admin/users'));
        self::assertTrue($policy->isAllowed($admin, '/api/v1/admin/users'));
        self::assertFalse($policy->isAllowed($customer, '/api/v1/admin/users'));
    }

    public function testResellerPathsOnlyAllowResellers(): void
    {
        $policy = new PortalAccessPolicy();

        $admin = new User('admin@example.test', UserType::Admin);
        $reseller = new User('reseller@example.test', UserType::Reseller);
        $customer = new User('customer@example.test', UserType::Customer);

        self::assertTrue($policy->isAllowed($reseller, '/reseller/customers'));
        self::assertTrue($policy->isAllowed($reseller, '/api/reseller/overview'));
        self::assertTrue($policy->isAllowed($reseller, '/api/v1/reseller/overview'));
        self::assertFalse($policy->isAllowed($admin, '/reseller/customers'));
        self::assertFalse($policy->isAllowed($customer, '/reseller/customers'));
    }

    public function testNonRestrictedPathsAllowAllUsers(): void
    {
        $policy = new PortalAccessPolicy();

        $admin = new User('admin@example.test', UserType::Admin);
        $reseller = new User('reseller@example.test', UserType::Reseller);
        $customer = new User('customer@example.test', UserType::Customer);

        self::assertTrue($policy->isAllowed($admin, '/profile'));
        self::assertTrue($policy->isAllowed($reseller, '/profile'));
        self::assertTrue($policy->isAllowed($customer, '/profile'));
        self::assertTrue($policy->isAllowed($customer, '/api/webspaces'));
    }
}
