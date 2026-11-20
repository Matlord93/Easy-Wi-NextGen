<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\Contracts\ContractCheckoutFlow;
use App\Module\Core\Domain\Entity\RentalAccount;
use App\Module\Core\Domain\Entity\ShopContract;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class CustomerContractController
{
    public function __construct(private ContractCheckoutFlow $flow, private EntityManagerInterface $em, private Environment $twig, private \App\Module\Core\Application\Contracts\RentalOverview $overview)
    {
    }

    #[Route('/marketplace/contracts/new/{id}', name: 'customer_contract_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function product(Request $request, int $id): Response
    {
        $this->customer($request);

        return $this->flow->product($request, $id, false);
    }

    #[Route('/marketplace/contracts/{id}', name: 'customer_contract_detail', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function contract(Request $request, int $id): Response
    {
        $this->customer($request);

        return $this->flow->contract($request, $id, false);
    }

    #[Route('/marketplace/contracts', name: 'customer_contracts', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->customer($request);
        $page = max(1, min(4000, $request->query->getInt('page', 1)));
        /** @var list<ShopContract> $contracts */
        $contracts = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->join('c.account', 'a')->where('a.customer = :customer')->setParameter('customer', $customer->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->orderBy('c.id', 'DESC')->setMaxResults(25)->setFirstResult(($page - 1) * 25)->getQuery()->getResult();

        return new Response($this->twig->render('shop/contracts/index.html.twig', ['contracts' => $contracts, 'accounts' => $this->em->getRepository(RentalAccount::class)->findBy(['customer' => $customer]), 'page' => $page, 'costs' => $this->overview->costs($customer, new \DateTimeImmutable()), 'activeNav' => 'shop']));
    }

    private function customer(Request $request): \App\Module\Core\Domain\Entity\User
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isCustomer()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }
}
