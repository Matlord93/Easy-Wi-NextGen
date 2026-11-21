<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Http\RequestActor;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\CustomerBillingDetails;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Repository\CustomerProfileRepository;
use App\Repository\InvoicePreferencesRepository;
use App\Security\PortalAccessResponse;
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
    public function __construct(private ShopProductRepository $products, private ShopProductVariantRepository $variants, private SiteResolver $sites, private ContractQuote $quotes, private RentalContracts $contracts, private CsrfTokenManagerInterface $csrf, private CmsSettingsProvider $cms, private CmsMaintenanceService $maintenance, private ThemeResolver $themes, private EntityManagerInterface $em, private Environment $twig, private \App\Module\Core\Application\ModuleRegistry $modules, private CheckoutSelection $selection, private CustomerProfileRepository $profiles, private InvoicePreferencesRepository $preferences, private AppSettingsServiceInterface $settings, private PortalAccessResponse $accessResponses, private \App\Module\Core\Application\Registrar\DomainRegistrarQueue $registrar, private RentalCapacity $capacity, private \App\Module\Gameserver\Application\GameserverInstallDirectory $directories)
    {
    }

    public function product(Request $request, int $id, bool $public): Response
    {
        if (!$this->modules->isEnabled('billing') || !$this->modules->isEnabled('shop')) {
            throw new NotFoundHttpException('This module is not enabled.');
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
        $isPost = $request->isMethod('POST');
        $input = JsonMap::strings($isPost ? $request->request->all() : $request->query->all());
        $intent = $request->query->getString('intent');
        $savedSelection = '' === $intent ? null : $this->selection->options($request, $product, $public, $intent);
        if (!$isPost && null !== $savedSelection) {
            $input = $savedSelection;
        }
        $selectionExpired = '' !== $intent && null === $savedSelection;
        $profile = $actor?->isCustomer() ? $this->profiles->findOneByCustomer($actor) : null;
        $profileRequired = $actor?->isCustomer() && (!CustomerBillingDetails::complete($profile) || !$actor->isCustomerAccessEnabled());
        $billing = CustomerBillingDetails::fromProfile($profile);
        $billingErrors = [];
        $error = null;
        $domainAvailable = null;
        if ($isPost && !$this->csrf->isTokenValid(new CsrfToken('rental-contract', $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $stage = $isPost ? ($input['stage'] ?? '') : '';
        try {
            $provisioningNode = $product->getProvisioningNode();
            if ('gameserver' === $product->getKind()->value && null !== $provisioningNode) {
                $this->directories->resolve($provisioningNode, (string) ($product->getProvisioningOptions()['instance_base_dir'] ?? ''));
            }
            $quote = $this->quotes->build($product, $input, $request->isMethod('POST'));
            if (($quote['registrar'] ?? '') === 'autodns' && '' !== $quote['hostname'] && 'register' === $quote['domain_action'] && 'order' !== $request->request->getString('stage')) {
                $domainAvailable = $this->registrar->available((string) $quote['hostname']);
            }
            if (in_array($stage, ['account', 'order', 'profile'], true)) {
                $availability = $this->capacity->inspect($product, $quote);
                if (!$availability['orderable']) {
                    throw new \DomainException($availability['label'].'. Bitte später erneut versuchen.');
                }
                if (null === $actor || 'account' === $stage) {
                    $target = $this->selection->save($request, $product, $public, $input);
                    if (null !== $actor) {
                        return new RedirectResponse($target, 303);
                    }
                    if ('1' === ($input['register'] ?? '') && $public && $this->settings->isRegistrationEnabled()) {
                        return new RedirectResponse('/register?target='.rawurlencode($target), 303);
                    }

                    return $this->accessResponses->signIn($request, $target);
                }
                if (!$actor->isCustomer()) {
                    throw new AccessDeniedHttpException('Checkout requires a customer account.');
                }
                if ('profile' === $stage && $profileRequired) {
                    $billing = CustomerBillingDetails::fields($input);
                    $billingErrors = CustomerBillingDetails::errors($billing);
                    if ([] === $billingErrors) {
                        $this->em->persist(CustomerBillingDetails::apply($actor, $profile, $billing));
                        if (null === $this->preferences->findOneByCustomer($actor)) {
                            $language = 'en' === $request->getLocale() ? 'en' : 'de';
                            $this->em->persist(new InvoicePreferences($actor, 'en' === $language ? 'en_GB' : 'de_DE', true, true, 'manual', $language));
                        }
                        $actor->setCustomerAccessEnabled(true);
                        $this->em->flush();

                        return new RedirectResponse($this->selection->save($request, $product, $public, $input), 303);
                    }
                } elseif ('order' === $stage && !$profileRequired) {
                    if (!$request->request->getBoolean('rental_terms')) {
                        throw new \InvalidArgumentException('Bitte die Vertrags- und Abrechnungsbedingungen bestätigen.');
                    }
                    $contract = $this->contracts->order($actor, $product, $input, $request->request->getString('nonce'), $request->request->getString('digest'));
                    $this->selection->forget($request, $request->getPathInfo().('?intent='.$intent));

                    return new RedirectResponse(($public ? '/shop/contracts/' : '/marketplace/contracts/').($contract->getId() ?? 0), 303);
                }
            }
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $error = str_starts_with($exception->getMessage(), 'AUTODNS_') ? 'Die Domainverfügbarkeit konnte gerade nicht geprüft werden. Bitte später erneut versuchen.' : $exception->getMessage();
            $quote = null;
        }
        $availability = $this->capacity->inspect($product, $quote);
        $ready = $availability['orderable'] && false !== $domainAvailable && null !== $quote && (!in_array($product->getKind()->value, ['kvm', 'proxmox', 'webspace', 'domain'], true) || '' !== (string) $quote['hostname']) && (!$product->getKind()->isVirtualMachine() || '' !== (string) $quote['ssh_keys']);

        return new Response($this->twig->render('shop/contracts/checkout.html.twig', $this->context($request, $public) + ['product' => $product, 'availability' => $availability, 'quote' => $quote, 'input' => $input, 'ready' => $ready, 'error' => $error, 'digest' => null !== $quote ? ContractQuote::digest($quote) : '', 'nonce' => bin2hex(random_bytes(16)), 'variants' => $this->variants->findBy(['product' => $product, 'active' => true], ['priceMonthlyCents' => 'ASC']), 'public_shop' => $public, 'domain_available' => $domainAvailable, 'selection_expired' => $selectionExpired, 'requires_account' => null === $actor, 'is_customer' => $actor?->isCustomer() ?? false, 'can_register' => $public && $this->settings->isRegistrationEnabled(), 'profile_required' => $profileRequired, 'billing' => $billing, 'billing_errors' => $billingErrors, 'billing_countries' => CustomerBillingDetails::COUNTRIES, 'billing_lengths' => CustomerBillingDetails::LENGTHS]), null !== $error || [] !== $billingErrors || ('order' === $stage && $profileRequired) ? 422 : 200, ['Cache-Control' => 'private, no-store']);
    }

    public function contract(Request $request, int $id, bool $public): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor) {
            return $this->accessResponses->signIn($request, $request->getPathInfo());
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

        return new Response($this->twig->render('shop/contracts/detail.html.twig', $this->context($request, $public, 'contracts') + ['contract' => $contract, 'public_shop' => $public]));
    }

    /** @return array<string,mixed> */
    private function context(Request $request, bool $public, string $activeNav = 'shop'): array
    {
        if (!$public) {
            return ['layout' => 'customer/base.html.twig', 'activeNav' => $activeNav];
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            throw new NotFoundHttpException();
        }
        $theme = $this->themes->resolveThemeKey($site);

        return ['layout' => 'themes/'.$theme.'/base.html.twig', 'active_theme' => $theme, 'template_key' => $theme, 'page' => ['slug' => 'shop', 'title' => 'Mietverträge'], 'cms_navigation' => $this->cms->getNavigationLinks($site), 'cms_footer_links' => $this->cms->getFooterLinks($site), 'cms_branding' => $this->cms->getBranding($site)];
    }
}
