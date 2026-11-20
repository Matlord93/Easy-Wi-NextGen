<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\Shop\ShopVariantEditor;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Repository\ShopProductRepository;
use App\Repository\ShopProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route('/admin/shop/products/{id<\d+>}/variants')]
final readonly class AdminShopVariantController
{
    public function __construct(private SiteResolver $sites, private ShopProductRepository $products, private ShopProductVariantRepository $variants, private ShopVariantEditor $editor, private EntityManagerInterface $em, private CsrfTokenManagerInterface $csrf, private AuditLoggerInterface $audit, private PaymentSettings $payments, private Environment $twig)
    {
    }

    #[Route('', name: 'admin_shop_variants', methods: ['GET'])]
    public function index(Request $request, int $id): Response
    {
        $product = $this->product($request, $id);
        $page = max(1, min(4000, $request->query->getInt('page', 1)));
        $variants = $this->variants->findBy(['product' => $product], ['id' => 'ASC'], 25, ($page - 1) * 24);

        return new Response($this->twig->render('admin/shop/variants/index.html.twig', ['activeNav' => 'shop-products', 'product' => $product, 'variants' => array_slice($variants, 0, 24), 'page' => $page, 'has_more' => count($variants) > 24, 'currency' => $this->payments->publicSettings()['currency']]));
    }

    #[Route('/new', name: 'admin_shop_variant_new', methods: ['GET', 'POST'])]
    #[Route('/{variantId<\d+>}/edit', name: 'admin_shop_variant_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id, int $variantId = 0): Response
    {
        $product = $this->product($request, $id);
        $variant = $variantId > 0 ? $this->variants->find($variantId) : null;
        if ($variantId > 0 && (null === $variant || $variant->getProduct()->getId() !== $id)) {
            throw new NotFoundHttpException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('shop-variant-'.$id, $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            try {
                $variant = $this->editor->save($product, $variant, JsonMap::strings($request->request->all()));
                $this->em->persist($variant);
                $this->em->flush();
                $this->audit->log(RequestActor::user($request), 'shop.variant.saved', ['product_id' => $id, 'variant_id' => $variant->getId()]);
                $this->em->flush();

                return new RedirectResponse('/admin/shop/products/'.$id.'/variants/'.($variant->getId() ?? 0).'/edit?saved=1', 303);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return new Response($this->twig->render('admin/shop/variants/editor.html.twig', ['activeNav' => 'shop-products', 'product' => $product, 'variant' => $variant, 'input' => $request->isMethod('POST') ? JsonMap::strings($request->request->all()) : null, 'error' => $error, 'currency' => $this->payments->publicSettings()['currency']]), null !== $error ? 422 : 200);
    }

    private function product(Request $request, int $id): ShopProduct
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }
        $site = $this->sites->resolve($request);
        $product = $this->products->find($id);
        if (null === $site || null === $product || $product->getSiteId() !== $site->getId()) {
            throw new NotFoundHttpException();
        }

        return $product;
    }
}
