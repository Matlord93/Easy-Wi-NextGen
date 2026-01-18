<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\DomainRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/domains')]
final class CustomerDomainController
{
    public function __construct(
        private readonly DomainRepository $domainRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_domains', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $domains = $this->domainRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/domains/index.html.twig', [
            'activeNav' => 'domains',
            'domains' => $this->normalizeDomains($domains),
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
}
