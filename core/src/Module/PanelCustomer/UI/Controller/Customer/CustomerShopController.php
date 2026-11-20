<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\ShopCheckoutService;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\ShopCategoryRepository;
use App\Repository\ShopOrderRepository;
use App\Repository\ShopProductRepository;
use App\Repository\ShopRentalRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/marketplace')]
#[RequiresModule('billing')]
final readonly class CustomerShopController
{
    public function __construct(private ShopCategoryRepository $categories, private ShopProductRepository $products, private ShopRentalRepository $rentals, private ShopOrderRepository $orders, private ShopCheckoutService $checkout, private PaymentSettings $payments, private SiteResolver $sites, private CsrfTokenManagerInterface $csrf, private Environment $twig, private \App\Module\Core\Application\Contracts\ContractCheckoutFlow $contractFlow)
    {
    }

    #[Route('', name: 'customer_shop', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->customer($request);
        $site = $this->sites->resolve($request);
        if (null === $site) {
            throw new NotFoundHttpException();
        }
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $productPage = max(1, min(4000, $request->query->getInt('product_page', 1)));
        $query = $this->products->createQueryBuilder('p')->join('p.category', 'c')->addSelect('c')
            ->where('p.siteId = :site AND p.isActive = true AND p.isCustomerActive = true AND c.active = true')->setParameter('site', $site->getId())
            ->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC')->setMaxResults(25)->setFirstResult(($productPage - 1) * 24);
        if ($request->query->getInt('category') > 0) {
            $query->andWhere('p.category = :category')->setParameter('category', $request->query->getInt('category'));
        }
        if ('' !== $search) {
            $query->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.description) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        /** @var list<ShopProduct> $products */
        $products = $query->getQuery()->getResult();
        $moreProducts = count($products) > 24;
        $products = array_slice($products, 0, 24);
        $page = max(1, min(4000, $request->query->getInt('page', 1)));

        return new Response($this->twig->render('customer/shop/index.html.twig', ['activeNav' => 'shop', 'categories' => $this->categories->findBy(['siteId' => $site->getId(), 'active' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']), 'products' => $products, 'product_page' => $productPage, 'more_products' => $moreProducts, 'rentals' => $this->rentals->findBy(['customer' => $customer], ['expiresAt' => 'ASC'], 100), 'orders' => $this->orders->findBy(['customer' => $customer], ['id' => 'DESC'], 25, ($page - 1) * 25), 'page' => $page, 'nonce' => bin2hex(random_bytes(16)), 'currency' => $this->payments->publicSettings()['currency']]));
    }

    #[Route('/checkout', name: 'customer_shop_checkout', methods: ['GET'])]
    public function checkout(Request $request): Response
    {
        $this->customer($request);

        return $this->contractFlow->product($request, $request->query->getInt('product_id'), false);
    }

    #[Route('/order', name: 'customer_shop_order', methods: ['POST'])]
    public function order(Request $request): Response
    {
        $customer = $this->customer($request);
        $this->assertCsrf($request);
        $product = $this->product($request, $request->request->getInt('product_id'));
        if (!$request->request->getBoolean('terms')) {
            return new Response('Please accept the order and subscription conditions.', 422);
        }
        try {
            $order = $this->checkout->create($customer, $product, $request->request->getInt('months', 1), $request->request->getInt('variant_id'), $request->request->getString('coupon'), $request->request->getBoolean('subscription'), $request->request->getString('nonce'), $request->request->getString('digest'), $request);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new Response($this->twig->render('customer/shop/_error.html.twig', ['message' => $e->getMessage()]), 422);
        }

        return new RedirectResponse('/marketplace/orders/'.($order->getId() ?? 0), 303);
    }

    #[Route('/orders/{id}', name: 'customer_shop_order_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(Request $request, int $id): Response
    {
        $customer = $this->customer($request);
        $order = $this->orders->find($id);
        if (null === $order || $order->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException();
        }

        return new Response($this->twig->render('customer/shop/order.html.twig', ['activeNav' => 'shop', 'order' => $order, 'methods' => $this->payments->methods()]));
    }

    #[Route('/rentals/{id}/extend', name: 'customer_shop_extend', methods: ['POST'])]
    public function extend(Request $request, int $id): Response
    {
        $customer = $this->customer($request);
        $this->assertCsrf($request);
        $rental = $this->rentals->find($id);
        if (null === $rental || $rental->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException();
        }
        try {
            $order = $this->checkout->extend($rental, $request->request->getInt('months', 1), $request->request->getString('nonce'), $request);
        } catch (\InvalidArgumentException|\DomainException $e) {
            return new Response($this->twig->render('customer/shop/_error.html.twig', ['message' => $e->getMessage()]), 422);
        }

        return new RedirectResponse('/marketplace/orders/'.($order->getId() ?? 0), 303);
    }

    #[Route('/rentals/{id}/cancel', name: 'customer_shop_subscription_cancel', methods: ['POST'])]
    public function cancel(Request $request, int $id): Response
    {
        $customer = $this->customer($request);
        $this->assertCsrf($request);
        $rental = $this->rentals->find($id);
        if (null === $rental || $rental->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException();
        }
        $this->checkout->cancelSubscription($rental);

        return new RedirectResponse('/marketplace', 303);
    }

    private function product(Request $request, int $id): ShopProduct
    {
        $product = $this->products->find($id);
        if (null === $product || $product->getSiteId() !== $this->sites->resolve($request)?->getId() || !$product->isActive() || !$product->isCustomerActive()) {
            throw new NotFoundHttpException();
        }

        return $product;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('shop', $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function customer(Request $request): User
    {
        $user = RequestActor::user($request);
        if (null === $user || UserType::Customer !== $user->getType()) {
            throw new AccessDeniedHttpException();
        }

        return $user;
    }
}
