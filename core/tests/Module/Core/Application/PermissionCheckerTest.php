<?php
declare(strict_types=1);
namespace App\Tests\Module\Core\Application;
use App\Module\Core\Application\PermissionChecker;
use App\Module\Core\Domain\Entity\AccessRole;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\Permission;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;
final class PermissionCheckerTest extends TestCase
{
    public function testCustomRoleGrantsOnlyAssignedPermission(): void
    {
        $user = new User('operator@example.test', UserType::Admin);
        $role = new AccessRole(1, 'Virtualization operator', [Permission::DockerManage, Permission::KvmManage]);
        self::assertTrue((new PermissionChecker())->isGranted($user, Permission::DockerManage, 1, [$role]));
        self::assertFalse((new PermissionChecker())->isGranted($user, Permission::SecurityManage, 1, [$role]));
        self::assertFalse((new PermissionChecker())->isGranted($user, Permission::DockerManage, 2, [$role]));
    }
    public function testSuperadminAlwaysAllowed(): void
    {
        self::assertTrue((new PermissionChecker())->isGranted(new User('root@example.test', UserType::Superadmin), Permission::SecurityManage, 1));
    }
}
