<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\MailboxRepository;
use App\Repository\WebspaceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/webspace')]
final class CustomerWebspaceController
{
    public function __construct(
        private readonly WebspaceRepository $webspaceRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailboxRepository $mailboxRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_webspace', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaces = $this->webspaceRepository->findByCustomer($customer);
        $domains = $this->domainRepository->findByCustomer($customer);
        $mailboxes = $this->mailboxRepository->findByCustomer($customer);

        $sslSummary = $this->buildSslSummary($domains);

        return new Response($this->twig->render('customer/webspace/index.html.twig', [
            'activeNav' => 'webspaces',
            'summary' => [
                'webspaces' => count($webspaces),
                'domains' => count($domains),
                'mailboxes' => count($mailboxes),
                'ssl_expiring' => $sslSummary['expiring'],
                'ssl_missing' => $sslSummary['missing'],
            ],
            'webspaces' => $this->normalizeWebspaces($webspaces),
        ]));
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
     * @return array{expiring: int, missing: int}
     */
    private function buildSslSummary(array $domains): array
    {
        $now = new \DateTimeImmutable();
        $threshold = $now->modify('+30 days');
        $expiring = 0;
        $missing = 0;

        foreach ($domains as $domain) {
            $expiresAt = $domain->getSslExpiresAt();
            if ($expiresAt === null) {
                $missing++;
                continue;
            }

            if ($expiresAt <= $threshold) {
                $expiring++;
            }
        }

        return [
            'expiring' => $expiring,
            'missing' => $missing,
        ];
    }

    /**
     * @param Webspace[] $webspaces
     * @return array<int, array<string, mixed>>
     */
    private function normalizeWebspaces(array $webspaces): array
    {
        return array_map(static function (Webspace $webspace): array {
            return [
                'id' => $webspace->getId(),
                'domain' => $webspace->getDomain(),
                'node' => [
                    'id' => $webspace->getNode()->getId(),
                    'name' => $webspace->getNode()->getName(),
                ],
                'php_version' => $webspace->getPhpVersion(),
                'quota' => $webspace->getQuota(),
                'status' => $webspace->getStatus(),
                'updated_at' => $webspace->getUpdatedAt(),
                'ftp_enabled' => $webspace->isFtpEnabled(),
                'sftp_enabled' => $webspace->isSftpEnabled(),
                'disk_limit_bytes' => $webspace->getDiskLimitBytes(),
            ];
        }, $webspaces);
    }

}
