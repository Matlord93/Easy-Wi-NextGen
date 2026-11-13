<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Application\Console\LiveConsoleAccess;
use App\Repository\InstanceRepository;
use App\Repository\UserSessionRepository;
use App\Security\SessionAuthenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[RequiresModule('game')]
final readonly class CustomerLiveConsoleController
{
    public function __construct(
        private InstanceRepository $instances,
        private UserSessionRepository $sessions,
        private AppSettingsService $settings,
        private LiveConsoleAccess $access,
        #[Autowire(service: 'limiter.instance_console_commands')]
        private \Symfony\Component\RateLimiter\RateLimiterFactory $connections,
        #[Autowire('%env(default::CONSOLE_PROXY_SECRET)%')] private ?string $proxySecret = null,
        #[Autowire('%env(default::CONSOLE_PUBLIC_ORIGIN)%')] private ?string $publicOrigin = null,
    ) {
    }

    #[Route('/api/v1/customer/instances/{id}/console/live-authorize', name: 'customer_instance_console_live_authorize', methods: ['GET'], requirements: ['id' => '[1-9][0-9]*'])]
    public function authorize(Request $request, int $id): Response
    {
        $deny = static fn (int $code): Response => new Response('', $code, ['Cache-Control' => 'no-store']);
        // Protect even a directly reachable PHP backend. This endpoint must never
        // expose an upstream credential or private target to a browser.
        if (strlen($this->proxySecret ?? '') < 32 || !hash_equals($this->proxySecret, (string) $request->headers->get('X-EasyWI-Console-Proxy'))) {
            return $deny(403);
        }
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return $deny(401);
        }
        $instance = $this->instances->find($id);
        if (!$instance || (!$actor->isAdmin() && $instance->getCustomer()?->getId() !== $actor->getId())) {
            return $deny(403);
        }
        $origin = $this->publicOrigin ?: $request->getSchemeAndHttpHost();
        $parsed = parse_url($origin);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['query']) || isset($parsed['fragment']) || ($parsed['path'] ?? '') !== '' || !hash_equals($origin, (string) $request->headers->get('X-EasyWI-Console-Origin'))) {
            return $deny(403);
        }
        if (!$this->connections->create('live-connect:'.$actor->getId().':'.$id)->consume()->isAccepted()) {
            return $deny(429);
        }
        $until = time() + 300;
        $tokens = [$request->cookies->get(SessionAuthenticator::CUSTOMER_SESSION_COOKIE), $request->cookies->get(SessionAuthenticator::ADMIN_SESSION_COOKIE)];
        $session = null;
        foreach ($tokens as $token) {
            if (!is_string($token) || $token === '') {
                continue;
            }
            $candidate = $this->sessions->findActiveByTokenHash(hash('sha256', $token));
            if ($candidate && $candidate->getUser()->getId() === $actor->getId()) {
                $session = $candidate;
                break;
            }
        }
        if (!$session) {
            return $deny(401);
        }
        if ($session->getExpiresAt()) {
            $until = min($until, $session->getExpiresAt()->getTimestamp());
        }
        if (($minutes = $this->settings->getSessionAbsoluteTimeoutMinutes()) > 0) {
            $until = min($until, $session->getCreatedAt()->getTimestamp() + $minutes * 60);
        }
        if (($minutes = $this->settings->getSessionIdleTimeoutMinutes()) > 0) {
            $until = min($until, time() + $minutes * 60);
        }
        try {
            $grant = $this->access->issue($instance, $actor, $origin, $until);
        } catch (\Throwable) {
            return $deny(503);
        }
        return new Response('', 204, ['Cache-Control' => 'private, no-store', 'X-Console-Target' => $grant['target'], 'X-Console-Authorization' => $grant['authorization']]);
    }
}
