<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ShopCoupon;
use App\Repository\ShopCategoryRepository;
use App\Repository\ShopCouponRepository;
use App\Repository\ShopOrderRepository;
use App\Repository\ShopProductRepository;
use App\Repository\ShopProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class AdminShopManagementController
{
    public function __construct(private SiteResolver $sites, private ShopProductRepository $products, private ShopCategoryRepository $categories, private ShopProductVariantRepository $variants, private ShopCouponRepository $coupons, private ShopOrderRepository $orders, private EntityManagerInterface $em, private AuditLoggerInterface $audit, private CsrfTokenManagerInterface $csrf, private Environment $twig, private \App\Module\Core\Application\Shop\ShopVariantEditor $variantEditor)
    {
    }

    #[Route('/admin/shop/manage', name: 'admin_shop_manage', methods: ['GET', 'POST'])]
    public function manage(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            return new Response('Site not found.', 404);
        }
        $siteId = $site->getId() ?? 0;
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('shop-manage', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                $action = $request->request->getString('action');
                if ('variant' === $action) {
                    $product = $this->products->find($request->request->getInt('product_id'));
                    if (null === $product || $product->getSiteId() !== $siteId) {
                        return new Response('Product not found.', 404);
                    }
                    $id = $request->request->getInt('variant_id');
                    $variant = $id > 0 ? $this->variants->find($id) : null;
                    if ($id > 0 && (null === $variant || $variant->getProduct()->getId() !== $product->getId())) {
                        return new Response('Variant not found.', 404);
                    }
                    $variant = $this->variantEditor->save($product, $variant, \App\Infrastructure\Config\JsonMap::strings($request->request->all()), true);
                    $this->em->persist($variant);
                } elseif ('product' === $action) {
                    $product = $this->products->find($request->request->getInt('product_id'));
                    if (null === $product || $product->getSiteId() !== $siteId) {
                        return new Response('Product not found.', 404);
                    }
                    $product->setSubscriptionEnabled($request->request->getBoolean('subscription'));
                } elseif ('category' === $action) {
                    $category = $this->categories->find($request->request->getInt('category_id'));
                    if (null === $category || $category->getSiteId() !== $siteId) {
                        return new Response('Category not found.', 404);
                    }
                    $description = trim($request->request->getString('description'));
                    if (mb_strlen($description) > 4000) {
                        throw new \InvalidArgumentException('Category description is too long.');
                    }
                    $category->configure($description, $request->request->getBoolean('active'));
                } elseif ('coupon' === $action) {
                    $code = strtoupper(trim($request->request->getString('code')));
                    if (null !== $this->coupons->findOneBy(['siteId' => $siteId, 'code' => $code])) {
                        throw new \InvalidArgumentException('Coupon already exists.');
                    }
                    $type = $request->request->getString('discount_type');
                    $value = 'percent' === $type ? $this->positive($request, 'discount_value') : BillingAmount::parse($request->request->getString('discount_value'));
                    $maximum = '' === $request->request->getString('maximum_uses') ? null : $this->positive($request, 'maximum_uses');
                    $coupon = new ShopCoupon($siteId, $code, $type, $value, BillingAmount::parse($request->request->getString('minimum', '0.00')), $maximum, $this->date($request->request->getString('valid_from')), $this->date($request->request->getString('valid_until'), true));
                    $this->em->persist($coupon);
                } elseif ('coupon-toggle' === $action) {
                    $coupon = $this->coupons->find($request->request->getInt('coupon_id'));
                    if (null === $coupon || $coupon->getSiteId() !== $siteId) {
                        return new Response('Coupon not found.', 404);
                    }
                    $coupon->setActive($request->request->getBoolean('active'));
                } else {
                    throw new \InvalidArgumentException('Unknown shop action.');
                }
                $this->audit->log($actor, 'shop.configuration.updated', ['site_id' => $siteId, 'action' => $action]);
                $this->em->flush();

                $target = match ($action) {
                    'coupon', 'coupon-toggle' => '/admin/shop/coupons',
                    'category' => '/admin/shop/categories',
                    default => '/admin/shop/manage',
                };

                return new RedirectResponse($target.'?saved=1', 303);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
        $page = max(1, min(4000, $request->query->getInt('page', 1)));
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $kind = \App\Module\Core\Domain\Enum\ShopProductKind::tryFrom($request->query->getString('kind'));
        $query = $this->products->createQueryBuilder('p')->where('p.siteId = :site')->setParameter('site', $siteId)->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC')->setMaxResults(25)->setFirstResult(($page - 1) * 24);
        if (null !== $kind) {
            $query->andWhere('p.kind = :kind')->setParameter('kind', $kind->value, \Doctrine\DBAL\Types\Types::STRING);
        }
        if ('' !== $search) {
            $query->andWhere('LOWER(p.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        /** @var list<\App\Module\Core\Domain\Entity\ShopProduct> $products */
        $products = $query->getQuery()->getResult();

        return new Response($this->twig->render('admin/shop/manage.html.twig', ['activeNav' => 'shop-manage', 'products' => array_slice($products, 0, 24), 'kinds' => \App\Module\Core\Domain\Enum\ShopProductKind::cases(), 'selected' => $kind, 'search' => $search, 'has_more' => count($products) > 24, 'page' => $page, 'error' => $error]), null !== $error ? 422 : 200);
    }

    #[Route('/admin/shop/orders', name: 'admin_shop_orders', methods: ['GET'])]
    public function orders(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            return new Response('Site not found.', 404);
        }
        $page = max(1, min(4000, $request->query->getInt('page', 1)));
        /** @var list<\App\Module\Core\Domain\Entity\ShopOrder> $orders */
        $orders = $this->orders->createQueryBuilder('o')->addSelect('p', 'c', 'i')->join('o.product', 'p')->join('o.customer', 'c')->leftJoin('o.invoice', 'i')->where('p.siteId = :site')->setParameter('site', $site->getId())->orderBy('o.id', 'DESC')->setMaxResults(25)->setFirstResult(($page - 1) * 24)->getQuery()->getResult();

        return new Response($this->twig->render('admin/shop/orders.html.twig', ['activeNav' => 'shop-orders', 'orders' => array_slice($orders, 0, 24), 'has_more' => count($orders) > 24, 'page' => $page]));
    }

    private function positive(Request $request, string $key): int
    {
        $value = filter_var($request->request->getString($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2_147_483_647]]);
        if (false === $value) {
            throw new \InvalidArgumentException('Invalid positive number: '.$key);
        }

        return $value;
    }

    private function date(string $value, bool $end = false): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid coupon date.');
        }

        return $end ? $date->setTime(23, 59, 59) : $date;
    }
}
