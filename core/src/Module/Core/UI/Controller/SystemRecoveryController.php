<?php

declare(strict_types=1);

namespace App\Module\Core\UI\Controller;

use App\Module\Core\Application\UpdateJobService;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

#[Route(path: '/system/recovery')]
final class SystemRecoveryController
{
    public function __construct(
        private readonly UpdateJobService $updateJobService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.recovery_db')]
        private readonly RateLimiterFactory $recoveryLimiter,
        private readonly Environment $twig,
        #[Autowire('%app.recovery_allowed_ips%')]
        private readonly array $allowedIps,
    ) {
    }

    #[Route(path: '/database', name: 'system_recovery_database', methods: ['GET'])]
    public function database(Request $request): Response
    {
        if (!$this->isAuthorized($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $migrationStatus = $this->updateJobService->getMigrationStatus();
        $latestJob = $this->updateJobService->getLatestJob();
        $logLines = $this->updateJobService->tailLog($latestJob['logPath'] ?? null);
        $versionInfo = $this->updateJobService->getVersionInfo();

        return new Response($this->twig->render('system/recovery_database.html.twig', [
            'versionInfo' => $versionInfo,
            'migrationStatus' => $migrationStatus,
            'latestJob' => $latestJob,
            'logLines' => $logLines,
            'notice' => $request->query->get('notice'),
            'csrfToken' => $this->csrfTokenManager->getToken('recovery_migrate')->getValue(),
        ]));
    }

    #[Route(path: '/database/migrate', name: 'system_recovery_database_migrate', methods: ['POST'])]
    public function migrate(Request $request): Response
    {
        if (!$this->isAuthorized($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeLimiter($request)) {
            throw new TooManyRequestsHttpException();
        }

        $token = new CsrfToken('recovery_migrate', (string) $request->request->get('csrf_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }

        $actor = $request->attributes->get('current_user');
        $createdBy = $actor instanceof User ? $actor->getEmail() : 'recovery';
        $job = $this->updateJobService->createJob('migrate', $createdBy);
        $this->updateJobService->triggerRunner($job['id']);

        return new RedirectResponse('/system/recovery/database?notice=job_created', Response::HTTP_SEE_OTHER);
    }

    #[Route(path: '/database/status', name: 'system_recovery_database_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $latestJob = $this->updateJobService->getLatestJob();
        $migrationStatus = $this->updateJobService->getMigrationStatus();

        return new JsonResponse([
            'job' => $latestJob,
            'migrations' => $migrationStatus,
        ]);
    }

    #[Route(path: '/database/log', name: 'system_recovery_database_log', methods: ['GET'])]
    public function log(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $latestJob = $this->updateJobService->getLatestJob();
        $lines = $this->updateJobService->tailLog($latestJob['logPath'] ?? null);

        return new JsonResponse([
            'job_id' => $latestJob['id'] ?? null,
            'lines' => $lines,
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        if ($actor instanceof User && $actor->getType() === UserType::Superadmin) {
            return true;
        }

        $clientIp = $request->getClientIp();
        return $clientIp !== null && in_array($clientIp, $this->allowedIps, true);
    }

    private function consumeLimiter(Request $request): bool
    {
        $key = $request->getClientIp() ?? 'unknown';
        $limiter = $this->recoveryLimiter->create($key);
        return $limiter->consume(1)->isAccepted();
    }
}
