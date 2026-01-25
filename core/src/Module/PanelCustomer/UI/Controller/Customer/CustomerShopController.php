<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\ShopProvisioningService;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\ShopRental;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\ShopCategoryRepository;
use App\Repository\ShopProductRepository;
use App\Repository\ShopRentalRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/marketplace')]
final class CustomerShopController
{
    public function __construct(
        private readonly ShopCategoryRepository $categoryRepository,
        private readonly ShopProductRepository $productRepository,
        private readonly ShopRentalRepository $rentalRepository,
        private readonly ShopProvisioningService $provisioningService,
        private readonly SiteResolver $siteResolver,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_shop', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $categories = $this->categoryRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);
        $products = $this->productRepository->findBy(['siteId' => $site->getId(), 'isActive' => true]);
        $rentals = $this->rentalRepository->findBy(['customer' => $customer], ['expiresAt' => 'ASC']);

        return new Response($this->twig->render('customer/shop/index.html.twig', [
            'activeNav' => 'shop',
            'categories' => $this->normalizeCategories($categories, $products),
            'rentals' => $this->normalizeRentals($rentals),
        ]));
    }

    #[Route(path: '/order', name: 'customer_shop_order', methods: ['POST'])]
    public function order(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $productId = (int) $request->request->get('product_id', 0);
        $months = (int) $request->request->get('months', 1);

        $product = $this->productRepository->find($productId);
        if ($product === null || $product->getSiteId() !== $site->getId() || !$product->isActive()) {
            return new Response('Product not found.', Response::HTTP_NOT_FOUND);
        }

        try {
            $this->provisioningService->provision($customer, $product, $months);
        } catch (\RuntimeException $exception) {
            return new Response($this->twig->render('customer/shop/_error.html.twig', [
                'message' => $exception->getMessage(),
            ]), Response::HTTP_BAD_REQUEST);
        }

        return new Response('', Response::HTTP_NO_CONTENT, [
            'HX-Redirect' => '/marketplace',
        ]);
    }

    #[Route(path: '/rentals/{id}/extend', name: 'customer_shop_extend', methods: ['POST'])]
    public function extend(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $months = (int) $request->request->get('months', 1);

        $rental = $this->rentalRepository->find($id);
        if ($rental === null || $rental->getCustomer()->getId() !== $customer->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->provisioningService->extendRental($rental, $months);

        return new Response('', Response::HTTP_NO_CONTENT, [
            'HX-Redirect' => '/marketplace',
        ]);
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
     * @param array<int, \App\Module\Core\Domain\Entity\ShopCategory> $categories
     * @param array<int, ShopProduct> $products
     */
    private function normalizeCategories(array $categories, array $products): array
    {
        $productsByCategory = [];
        foreach ($products as $product) {
            $productsByCategory[$product->getCategory()->getId()][] = $product;
        }

        return array_map(function ($category) use ($productsByCategory): array {
            $categoryProducts = $productsByCategory[$category->getId()] ?? [];

            return [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'products' => array_map([$this, 'normalizeProduct'], $categoryProducts),
            ];
        }, $categories);
    }

    private function normalizeProduct(ShopProduct $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'image_url' => $product->getImageUrl(),
            'price_monthly' => number_format($product->getPriceMonthlyCents() / 100, 2, '.', ''),
            'cpu_limit' => $product->getCpuLimit(),
            'ram_limit' => $product->getRamLimit(),
            'disk_limit' => $product->getDiskLimit(),
        ];
    }

    /**
     * @param ShopRental[] $rentals
     */
    private function normalizeRentals(array $rentals): array
    {
        return array_map(static fn (ShopRental $rental): array => [
            'id' => $rental->getId(),
            'product_name' => $rental->getProduct()->getName(),
            'instance_id' => $rental->getInstance()->getId(),
            'expires_at' => $rental->getExpiresAt()->format('Y-m-d'),
        ], $rentals);
    }
}
