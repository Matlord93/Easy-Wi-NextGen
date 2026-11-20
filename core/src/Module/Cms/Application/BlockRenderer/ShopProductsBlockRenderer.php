<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\ModuleRegistry;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Repository\ShopProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final readonly class ShopProductsBlockRenderer implements BlockRendererInterface
{
    public function __construct(private ShopProductRepository $products, private CmsSiteResolver $sites, private RequestStack $requests, private ModuleRegistry $modules, private PaymentSettings $payments, private Environment $twig)
    {
    }

    #[\Override]
    public function supports(string $blockType): bool
    {
        return 'shop_products' === $blockType;
    }

    #[\Override]
    public function render(array $payload): string
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request || !$this->modules->isEnabled('cms') || !$this->modules->isEnabled('shop') || !$this->modules->isEnabled('billing')) {
            return '';
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            return '';
        }
        $query = $this->products->createQueryBuilder('p')->join('p.category', 'c')->addSelect('c')
            ->where('p.siteId = :site AND p.isActive = true AND p.isPublicActive = true AND c.active = true AND c.siteId = :site')->setParameter('site', $site->getId())->orderBy('p.name', 'ASC')->setMaxResults(max(1, min(24, (int) ($payload['limit'] ?? 6))));
        $category = (int) ($payload['category_id'] ?? 0);
        if ($category > 0) {
            $query->andWhere('p.category = :category')->setParameter('category', $category);
        }
        /** @var list<ShopProduct> $products */
        $products = $query->getQuery()->getResult();

        return $this->twig->render('public/shop/block.html.twig', ['products' => $products, 'currency' => $this->payments->publicSettings()['currency']]);
    }
}
