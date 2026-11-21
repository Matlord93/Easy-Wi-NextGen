<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Security\PortalAccessPolicy;
use App\Security\PortalAccessResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SessionGuardSubscriberCmsAccessTest extends TestCase
{
    public function testAnonymousBrowserAndAjaxGetTheAppropriateSignInResponse(): void
    {
        $templates = new \Twig\Loader\ArrayLoader(['public/auth/access_problem.html.twig' => '{{ destination }}']);
        $responses = new PortalAccessResponse($this->createStub(TranslatorInterface::class), new \Twig\Environment($templates));
        $response = $responses->signIn(Request::create('/admin/cms/pages'));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('login_required', $response->headers->get('X-EasyWi-Problem'));
        self::assertSame('/login?target=%2Fadmin%2Fcms%2Fpages', $response->getContent());

        $request = Request::create('/admin/cms/pages/table', cookies: ['easywi_session' => 'expired'], server: [
            'HTTP_HX_REQUEST' => 'true', 'HTTP_REFERER' => 'http://localhost/admin/cms/pages',
        ]);
        $response = $responses->signIn($request);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('session_expired', $response->headers->get('X-EasyWi-Problem'));
        self::assertSame('/login?target=%2Fadmin%2Fcms%2Fpages', json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['action_url']);
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
