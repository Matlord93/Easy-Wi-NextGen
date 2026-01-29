<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Application\AuditLogger;
use App\Repository\DomainRepository;
use App\Repository\WebspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/domains')]
final class CustomerDomainController
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_domains', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $domains = $this->domainRepository->findByCustomer($customer);
        $webspaces = $this->webspaceRepository->findByCustomer($customer);

        return $this->renderPage($domains, $webspaces);
    }

    #[Route(path: '', name: 'customer_domains_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $domainName = $this->normalizeDomain((string) $request->request->get('domain', ''));
        $webspaceId = (int) $request->request->get('webspace_id', 0);

        $errors = [];
        if ($domainName === '') {
            $errors[] = 'Domain is required.';
        }
        if ($webspaceId <= 0) {
            $errors[] = 'Webspace selection is required.';
        }

        $webspace = $webspaceId > 0 ? $this->webspaceRepository->find($webspaceId) : null;
        if ($webspace === null || $webspace->getCustomer()->getId() !== $customer->getId()) {
            $errors[] = 'Webspace not found.';
        }

        if ($domainName !== '' && $this->domainRepository->findOneBy(['name' => $domainName]) !== null) {
            $errors[] = 'Domain already exists.';
        }

        if ($errors !== []) {
            return $this->renderPage(
                $this->domainRepository->findByCustomer($customer),
                $this->webspaceRepository->findByCustomer($customer),
                $errors,
                Response::HTTP_BAD_REQUEST,
            );
        }

        $domainEntity = new Domain($customer, $webspace, $domainName);
        $this->entityManager->persist($domainEntity);
        $this->entityManager->flush();

        $systemUsername = $webspace->getSystemUsername();
        $jobPayload = [
            'agent_id' => $webspace->getNode()->getId(),
            'domain_id' => (string) $domainEntity->getId(),
            'domain' => $domainEntity->getName(),
            'web_root' => $webspace->getPath(),
            'source_dir' => $webspace->getDocroot(),
            'docroot' => $webspace->getDocroot(),
            'nginx_vhost_path' => sprintf('/etc/easywi/web/nginx/vhosts/%s.conf', $domainEntity->getName()),
            'nginx_include_path' => sprintf('/etc/easywi/web/nginx/includes/%s.conf', $systemUsername),
            'php_fpm_listen' => sprintf('/run/easywi/php-fpm/%s.sock', $systemUsername),
            'logs_dir' => rtrim($webspace->getPath(), '/') . '/logs',
        ];

        $job = new Job('domain.add', $jobPayload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($customer, 'domain.created', [
            'domain_id' => $domainEntity->getId(),
            'webspace_id' => $webspace->getId(),
            'job_id' => $job->getId(),
        ]);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_SEE_OTHER, ['Location' => '/domains']);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @param Domain[] $domains
     */
    private function normalizeDomains(array $domains): array
    {
        return array_map(static function (Domain $domain): array {
            return [
                'id' => $domain->getId(),
                'name' => $domain->getName(),
                'status' => $domain->getStatus(),
                'webspace' => $domain->getWebspace()->getDomain(),
                'ssl_expires_at' => $domain->getSslExpiresAt(),
                'updated_at' => $domain->getUpdatedAt(),
            ];
        }, $domains);
    }

    /**
     * @param Domain[] $domains
     * @param Webspace[] $webspaces
     */
    private function renderPage(array $domains, array $webspaces, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('customer/domains/index.html.twig', [
            'activeNav' => 'domains',
            'domains' => $this->normalizeDomains($domains),
            'webspaces' => $this->normalizeWebspaces($webspaces),
            'errors' => $errors,
        ]), $status);
    }

    /**
     * @param Webspace[] $webspaces
     */
    private function normalizeWebspaces(array $webspaces): array
    {
        return array_map(static function (Webspace $webspace): array {
            return [
                'id' => $webspace->getId(),
                'domain' => $webspace->getDomain(),
                'status' => $webspace->getStatus(),
            ];
        }, $webspaces);
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);

        return $domain ?? '';
    }
}
