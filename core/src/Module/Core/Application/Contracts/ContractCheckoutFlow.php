<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Http\RequestActor;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Repository\ShopProductRepository;
use App\Repository\ShopProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class ContractCheckoutFlow
{
    public function __construct(private ShopProductRepository $products, private ShopProductVariantRepository $variants, private SiteResolver $sites, private ContractQuote $quotes, private RentalContracts $contracts, private CsrfTokenManagerInterface $csrf, private CmsSettingsProvider $cms, private CmsMaintenanceService $maintenance, private ThemeResolver $themes, private EntityManagerInterface $em, private Environment $twig, private \App\Module\Core\Application\ModuleRegistry $modules, private \App\Module\Core\Application\SecretsCryptoInterface $crypto, private \App\Module\Core\Application\Registrar\DomainRegistrarQueue $registrar, private RentalCapacity $capacity, private \App\Module\Gameserver\Application\GameserverInstallDirectory $directories)
    {
    }

    public function product(Request $request, int $id, bool $public): Response
    {
        if (!$this->modules->isEnabled('billing') || !$this->modules->isEnabled('shop')) {
            throw new NotFoundHttpException();
        }
        $product = $this->products->find($id);
        $site = $this->sites->resolve($request);
        if (null === $site || null === $product || $product->getSiteId() !== $site->getId() || !$product->isActive() || !($public ? $product->isPublicActive() : $product->isCustomerActive()) || ($public && (!$product->getCategory()->isActive() || $product->getCategory()->getSiteId() !== $site->getId()))) {
            throw new NotFoundHttpException();
        }
        if ($public && $this->maintenance->resolve($request, $site)['active']) {
            return new Response('Der Shop befindet sich in Wartung.', 503);
        }
        $actor = RequestActor::user($request);
        $input = JsonMap::strings($request->isMethod('POST') ? $request->request->all() : $request->query->all());
        $intent = $request->query->getString('intent');
        if ('' !== $intent) {
            $input = JsonMap::strings($request->getSession()->remove('rental_intent_'.$intent));
            if (isset($input['auth_code_encrypted'])) {
                $input['auth_code'] = $this->crypto->decrypt($input['auth_code_encrypted']);
                unset($input['auth_code_encrypted']);
            }
        }
        $error = null;
        $domainAvailable = null;
        if ($request->isMethod('POST') && !$this->csrf->isTokenValid(new CsrfToken('rental-contract', $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        try {
            $provisioningNode = $product->getProvisioningNode();
            if ('gameserver' === $product->getKind()->value && null !== $provisioningNode) {
                $this->directories->resolve($provisioningNode, (string) ($product->getProvisioningOptions()['instance_base_dir'] ?? ''));
            }
            $quote = $this->quotes->build($product, $input, $request->isMethod('POST'));
            if (($quote['registrar'] ?? '') === 'autodns' && '' !== $quote['hostname'] && 'register' === $quote['domain_action'] && 'order' !== $request->request->getString('stage')) {
                $domainAvailable = $this->registrar->available((string) $quote['hostname']);
            }
            if ($request->isMethod('POST') && 'order' === $request->request->getString('stage')) {
                $availability = $this->capacity->inspect($product, $quote);
                if (!$availability['orderable']) {
                    throw new \DomainException($availability['label'].'. Bitte später erneut versuchen.');
                }
                if (null === $actor) {
                    $key = bin2hex(random_bytes(16));
                    $saved = $input;
                    if (isset($saved['auth_code']) && '' !== $saved['auth_code']) {
                        $saved['auth_code_encrypted'] = $this->crypto->encrypt($saved['auth_code']);
                        unset($saved['auth_code']);
                    }
                    $request->getSession()->set('rental_intent_'.$key, $saved);
                    $target = ($public ? '/shop/products/' : '/marketplace/contracts/new/').$id.'?intent='.$key;

                    return new RedirectResponse('/login?target='.rawurlencode($target), 303);
                }
                if (!$actor->isCustomer()) {
                    throw new AccessDeniedHttpException('Ein Kundenkonto ist zum Bestellen erforderlich.');
                }
                if (!$request->request->getBoolean('rental_terms')) {
                    throw new \InvalidArgumentException('Bitte die Vertrags- und Abrechnungsbedingungen bestätigen.');
                }
                $contract = $this->contracts->order($actor, $product, $input, $request->request->getString('nonce'), $request->request->getString('digest'));

                return new RedirectResponse(($public ? '/shop/contracts/' : '/marketplace/contracts/').($contract->getId() ?? 0), 303);
            }
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $error = str_starts_with($exception->getMessage(), 'AUTODNS_') ? 'Die Domainverfügbarkeit konnte gerade nicht geprüft werden. Bitte später erneut versuchen.' : $exception->getMessage();
            $quote = null;
        }
        $availability = $this->capacity->inspect($product, $quote);
        $ready = $availability['orderable'] && false !== $domainAvailable && null !== $quote && (!in_array($product->getKind()->value, ['kvm', 'webspace', 'domain'], true) || '' !== (string) $quote['hostname']) && ('kvm' !== $product->getKind()->value || '' !== (string) $quote['ssh_keys']);

        return new Response($this->twig->render('shop/contracts/checkout.html.twig', $this->context($request, $public) + ['product' => $product, 'availability' => $availability, 'quote' => $quote, 'input' => $input, 'ready' => $ready, 'error' => $error, 'digest' => null !== $quote ? ContractQuote::digest($quote) : '', 'nonce' => bin2hex(random_bytes(16)), 'variants' => $this->variants->findBy(['product' => $product, 'active' => true], ['priceMonthlyCents' => 'ASC']), 'public_shop' => $public, 'domain_available' => $domainAvailable]), null !== $error ? 422 : 200);
    }

    public function contract(Request $request, int $id, bool $public): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor) {
            return new RedirectResponse('/login?target='.rawurlencode($request->getPathInfo()));
        }
        $contract = $this->em->find(ShopContract::class, $id);
        if (null === $contract || $contract->getCustomer()->getId() !== $actor->getId()) {
            throw new NotFoundHttpException();
        }
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('rental-cancel-'.$id, $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $this->contracts->cancel($contract, $actor, $request);

            return new RedirectResponse(($public ? '/shop/contracts/' : '/marketplace/contracts/').$id, 303);
        }

        return new Response($this->twig->render('shop/contracts/detail.html.twig', $this->context($request, $public) + ['contract' => $contract, 'public_shop' => $public]));
    }

    /** @return array<string,mixed> */
    private function context(Request $request, bool $public): array
    {
        if (!$public) {
            return ['layout' => 'customer/base.html.twig', 'activeNav' => 'shop'];
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            throw new NotFoundHttpException();
        }
        $theme = $this->themes->resolveThemeKey($site);

        return ['layout' => 'themes/'.$theme.'/base.html.twig', 'active_theme' => $theme, 'template_key' => $theme, 'page' => ['slug' => 'shop', 'title' => 'Mietverträge'], 'cms_navigation' => $this->cms->getNavigationLinks($site), 'cms_footer_links' => $this->cms->getFooterLinks($site), 'cms_branding' => $this->cms->getBranding($site)];
    }
}
