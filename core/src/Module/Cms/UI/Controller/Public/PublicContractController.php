<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Core\Application\Contracts\ContractCheckoutFlow;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Core\Attribute\RequiresModule;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[RequiresModule('shop'), RequiresModule('billing')]
final readonly class PublicContractController
{
    public function __construct(private ContractCheckoutFlow $flow, private CmsSiteResolver $sites)
    {
    }

    #[Route('/shop/products/{id}', name: 'public_cms_shop_product', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function product(Request $request, int $id): Response
    {
        if ($this->sites->resolve($request) === null) {
            return new Response('Site not found.', 404);
        }
        return $this->flow->product($request, $id, true);
    }

    #[Route('/shop/contracts/{id}', name: 'public_cms_shop_contract', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function contract(Request $request, int $id): Response
    {
        if ($this->sites->resolve($request) === null) {
            return new Response('Site not found.', 404);
        }
        return $this->flow->contract($request, $id, true);
    }
}
