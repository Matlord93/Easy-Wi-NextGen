<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Security\PortalAccessPolicy;
use App\Security\SessionGuardSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SessionGuardSubscriberCmsAccessTest extends TestCase
{
    public function testAnonymousResponseIsUnauthorizedForAdminCmsPath(): void
    {
        $subscriber = (new \ReflectionClass(SessionGuardSubscriber::class))->newInstanceWithoutConstructor();
        $translatorProp = new \ReflectionProperty(SessionGuardSubscriber::class, 'translator');
        $translatorProp->setAccessible(true);
        $translatorProp->setValue($subscriber, $this->createMock(TranslatorInterface::class));
        $method = new \ReflectionMethod(SessionGuardSubscriber::class, 'unauthorizedResponse');
        $response = $method->invoke($subscriber, Request::create('/admin/cms/pages'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testPolicyDeniesNonAdminForAdminCmsPath(): void
    {
        $policy = new PortalAccessPolicy();

        self::assertFalse($policy->isAllowed(new User('customer@example.test', UserType::Customer), '/admin/cms/pages'));
        self::assertFalse($policy->isAllowed(new User('reseller@example.test', UserType::Reseller), '/admin/cms/pages'));
    }

    public function testPolicyAllowsAdminForAdminCmsPath(): void
    {
        $policy = new PortalAccessPolicy();

        self::assertTrue($policy->isAllowed(new User('admin@example.test', UserType::Admin), '/admin/cms/pages'));
        self::assertTrue($policy->isAllowed(new User('superadmin@example.test', UserType::Superadmin), '/admin/cms/pages'));
    }
}
