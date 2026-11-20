<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ShopCoupon;
use App\Repository\ShopCouponRepository;
use Doctrine\DBAL\LockMode;
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

#[Route('/admin/shop/coupons')]
final readonly class AdminShopCouponController
{
    public function __construct(private SiteResolver $sites, private ShopCouponRepository $coupons, private EntityManagerInterface $em, private CsrfTokenManagerInterface $csrf, private AuditLoggerInterface $audit, private PaymentSettings $payments, private Environment $twig)
    {
    }

    #[Route('', name: 'admin_shop_coupons', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $siteId = $this->siteId($request);
        $page = max(1, min(4000, $request->query->getInt('page', 1)));
        $coupons = $this->coupons->findBy(['siteId' => $siteId], ['id' => 'DESC'], 25, ($page - 1) * 24);

        return new Response($this->twig->render('admin/shop/coupons/index.html.twig', ['activeNav' => 'shop-manage', 'coupons' => array_slice($coupons, 0, 24), 'page' => $page, 'has_more' => count($coupons) > 24, 'currency' => $this->payments->publicSettings()['currency']]));
    }

    #[Route('/new', name: 'admin_shop_coupon_new', methods: ['GET', 'POST'])]
    #[Route('/{id<\d+>}/edit', name: 'admin_shop_coupon_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id = 0): Response
    {
        $siteId = $this->siteId($request);
        $coupon = $id > 0 ? $this->coupons->find($id) : null;
        if ($id > 0 && (null === $coupon || $coupon->getSiteId() !== $siteId)) {
            throw new NotFoundHttpException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('shop-coupon-'.$id, $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            try {
                $code = strtoupper(trim($request->request->getString('code')));
                if (null !== $coupon && $code !== $coupon->getCode()) {
                    throw new \InvalidArgumentException('Der Code eines vorhandenen Gutscheins bleibt unverändert.');
                }
                if (null === $coupon && null !== $this->coupons->findOneBy(['siteId' => $siteId, 'code' => $code])) {
                    throw new \InvalidArgumentException('Dieser Gutscheincode wird bereits verwendet.');
                }
                $type = $request->request->getString('discount_type');
                $value = 'percent' === $type ? $this->positive($request->request->getString('discount_value')) : BillingAmount::parse($request->request->getString('discount_value'));
                $minimum = BillingAmount::parse($request->request->getString('minimum', '0.00'));
                if ($value > 2_147_483_647 || $minimum > 2_147_483_647) {
                    throw new \InvalidArgumentException('Der Betrag überschreitet das unterstützte Maximum.');
                }
                $maximum = '' === $request->request->getString('maximum_uses') ? null : $this->positive($request->request->getString('maximum_uses'));
                $validated = new ShopCoupon($siteId, $code, $type, $value, $minimum, $maximum, $this->date($request->request->getString('valid_from')), $this->date($request->request->getString('valid_until'), true), $request->request->getBoolean('active'));
                if (null === $coupon) {
                    $coupon = $validated;
                    $this->em->persist($coupon);
                    $this->em->flush();
                } else {
                    $this->em->wrapInTransaction(function () use ($coupon, $validated): void {
                        // Reload the live redemption count while concurrent checkouts are locked out.
                        $this->em->refresh($coupon, LockMode::PESSIMISTIC_WRITE);
                        $coupon->configure($validated->getDiscountType(), $validated->getDiscountValue(), $validated->getMinimumCents(), $validated->getMaximumUses(), $validated->getValidFrom(), $validated->getValidUntil(), $validated->isActive());
                    });
                }
                $this->audit->log(RequestActor::user($request), 'shop.coupon.saved', ['site_id' => $siteId, 'coupon_id' => $coupon->getId()]);
                $this->em->flush();

                return new RedirectResponse('/admin/shop/coupons/'.($coupon->getId() ?? 0).'/edit?saved=1', 303);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return new Response($this->twig->render('admin/shop/coupons/editor.html.twig', ['activeNav' => 'shop-manage', 'coupon' => $coupon, 'input' => $request->isMethod('POST') ? JsonMap::strings($request->request->all()) : null, 'error' => $error, 'currency' => $this->payments->publicSettings()['currency']]), null !== $error ? 422 : 200);
    }

    private function siteId(Request $request): int
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            throw new NotFoundHttpException();
        }

        return $site->getId() ?? 0;
    }

    private function positive(string $raw): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2_147_483_647]]);
        if (false === $value) {
            throw new \InvalidArgumentException('Bitte eine gültige positive ganze Zahl angeben.');
        }

        return $value;
    }

    private function date(string $raw, bool $end = false): ?\DateTimeImmutable
    {
        if ('' === $raw) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException('Bitte ein gültiges Gutscheindatum angeben.');
        }

        return $end ? $date->setTime(23, 59, 59) : $date;
    }
}
