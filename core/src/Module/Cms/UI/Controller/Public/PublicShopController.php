<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Http\MaintenancePageResponseFactory;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Repository\ShopCategoryRepository;
use App\Repository\ShopProductRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[RequiresModule('billing')]
#[RequiresModule('shop')]
final readonly class PublicShopController
{
    public function __construct(private CmsSiteResolver $sites, private ShopCategoryRepository $categories, private ShopProductRepository $products, private CmsSettingsProvider $settings, private CmsMaintenanceService $maintenance, private MaintenancePageResponseFactory $maintenancePages, private ThemeResolver $themes, private PaymentSettings $payments, private Environment $twig)
    {
    }

    #[Route('/shop', name: 'public_cms_shop', methods: ['GET'])]
    public function catalog(Request $request): Response
    {
        $site = $this->sites->resolve($request);
        if (null === $site) {
            return new Response('Site not found.', 404);
        }
        $maintenance = $this->maintenance->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePages->create($maintenance);
        }
        $categories = $this->categories->findBy(['siteId' => $site->getId(), 'active' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
        $page = max(1, min(4000, $request->query->getInt('page', 1)));
        $query = $this->products->createQueryBuilder('p')->join('p.category', 'c')->addSelect('c')
            ->where('p.siteId = :site AND p.isActive = true AND p.isPublicActive = true AND c.active = true AND c.siteId = :site')->setParameter('site', $site->getId())
            ->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC')->setMaxResults(25)->setFirstResult(($page - 1) * 24);
        if ($request->query->getInt('category') > 0) {
            $query->andWhere('p.category = :category')->setParameter('category', $request->query->getInt('category'));
        }
        /** @var list<ShopProduct> $products */
        $products = $query->getQuery()->getResult();
        $hasMore = count($products) > 24;
        $products = array_slice($products, 0, 24);
        $theme = $this->themes->resolveThemeKey($site);

        return new Response($this->twig->render('public/shop/index.html.twig', ['active_theme' => $theme, 'template_key' => $theme, 'page' => ['slug' => 'shop', 'title' => 'Shop'], 'cms_navigation' => $this->settings->getNavigationLinks($site), 'cms_footer_links' => $this->settings->getFooterLinks($site), 'cms_branding' => $this->settings->getBranding($site), 'categories' => $categories, 'products' => $products, 'catalog_page' => $page, 'has_more' => $hasMore, 'currency' => $this->payments->publicSettings()['currency']]));
    }
}
