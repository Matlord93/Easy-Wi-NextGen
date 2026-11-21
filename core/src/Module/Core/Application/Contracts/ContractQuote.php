<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Application\Billing\PaymentSettings;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Repository\ShopCouponRepository;
use App\Repository\ShopProductVariantRepository;

final readonly class ContractQuote
{
    public function __construct(private ShopProductVariantRepository $variants, private ShopCouponRepository $coupons, private PaymentSettings $settings, private \App\Repository\SiteRepository $sites)
    {
    }

    /**
     * @param array<string,string> $input
     *
     * @return array<string,int|string|bool>
     */
    public function build(ShopProduct $product, array $input, bool $requireIdentity = false): array
    {
        if (!$product->isActive() || !$product->getCategory()->isActive()) {
            throw new \InvalidArgumentException('Dieses Produkt ist nicht verfügbar.');
        }
        $variantId = (int) ($input['variant_id'] ?? 0);
        $variant = $variantId > 0 ? $this->variants->find($variantId) : null;
        if ($variantId > 0 && (null === $variant || !$variant->isActive() || $variant->getProduct()->getId() !== $product->getId())) {
            throw new \InvalidArgumentException('Diese Variante ist nicht verfügbar.');
        }
        $mode = $input['billing_mode'] ?? 'postpaid';
        if (!in_array($mode, ['wallet', 'postpaid'], true)) {
            throw new \InvalidArgumentException('Ungültige Zahlungsweise.');
        }
        $options = $product->getProvisioningOptions();
        $price = $variant?->getPriceMonthlyCents() ?? $product->getPriceMonthlyCents();
        if ($price < 0 || $price > 10_000_000) {
            throw new \InvalidArgumentException('Ungültiger Mietpreis.');
        }
        $term = ShopProductKind::Domain === $product->getKind() ? (int) ($options['term_months'] ?? 12) : 0;
        if (0 !== $term && !in_array($term, [12, 24, 36], true)) {
            throw new \InvalidArgumentException('Domainlaufzeit muss 12, 24 oder 36 Monate betragen.');
        }
        $period = $price * max(1, $term);
        $couponCode = strtoupper(trim($input['coupon'] ?? ''));
        $discount = 0;
        if ('' !== $couponCode) {
            $coupon = $this->coupons->findOneBy(['siteId' => $product->getSiteId(), 'code' => $couponCode]);
            $discount = $coupon?->discountFor($period, new \DateTimeImmutable()) ?? 0;
            if ($discount < 1) {
                throw new \InvalidArgumentException('Gutschein ist nicht gültig oder sein Mindestbetrag ist nicht erreicht.');
            }
        }
        $cpu = $variant?->getCpuLimit() ?? $product->getCpuLimit();
        $ram = $variant?->getRamLimit() ?? $product->getRamLimit();
        $disk = $variant?->getDiskLimit() ?? $product->getDiskLimit();
        if ($cpu < 1 || $cpu > 21_474_836 || $ram < 1 || $ram > 2_097_151 || $disk < 1 || $disk > 2_097_151 || ($product->getKind()->isVirtualMachine() && ($cpu > 256 || $ram > 1024 || $disk > 2048))) {
            throw new \InvalidArgumentException('Ungültige Ressourcen.');
        }
        $hostname = strtolower(trim($input['hostname'] ?? ''));
        if (($requireIdentity || '' !== $hostname) && in_array($product->getKind(), [ShopProductKind::Domain, ShopProductKind::Webspace], true)) {
            if ('' === $hostname) {
                throw new \InvalidArgumentException('Ein Domainname ist erforderlich.');
            }
            $ascii = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii) || strlen($ascii) > 253 || !str_contains($ascii, '.') || false === filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || false !== filter_var($ascii, FILTER_VALIDATE_IP)) {
                throw new \InvalidArgumentException('Bitte einen gültigen Domainnamen angeben.');
            }
            $hostname = $ascii;
            if (ShopProductKind::Webspace === $product->getKind() && null !== $this->sites->findOneByHost($hostname)) {
                throw new \InvalidArgumentException('Dieser Hostname wird vom Panel oder CMS verwendet.');
            }
        } elseif (($requireIdentity || '' !== $hostname) && $product->getKind()->isVirtualMachine() && !preg_match('/^[a-z][a-z0-9-]{0,47}$/D', $hostname)) {
            throw new \InvalidArgumentException('Ungültiger VM-Hostname.');
        }
        $action = $input['domain_action'] ?? 'register';
        $authinfo = trim($input['auth_code'] ?? '');
        if (ShopProductKind::Domain === $product->getKind()) {
            if (!in_array($action, ['register', 'transfer'], true) || strlen($authinfo) > 255 || preg_match('/[\x00-\x1f\x7f]/', $authinfo)) {
                throw new \InvalidArgumentException('Domainauftrag oder Authcode ungültig.');
            }
            if ('transfer' === $action && ($options['registrar'] ?? '') !== 'autodns') {
                throw new \InvalidArgumentException('Domaintransfers erfordern eine Registrar-Anbindung.');
            }
            if ('transfer' === $action && $requireIdentity && '' === $authinfo) {
                throw new \InvalidArgumentException('Für den Transfer ist der Authcode erforderlich.');
            }
            $tld = (string) ($options['tld'] ?? '');
            if ('' !== $hostname && '' !== $tld && !str_ends_with($hostname, '.'.$tld)) {
                throw new \InvalidArgumentException('Der Domainname muss zur angebotenen Endung passen.');
            }
        }
        $keys = trim($input['ssh_keys'] ?? '');
        if ($product->getKind()->isVirtualMachine() && ($requireIdentity || '' !== $keys)) {
            $lines = preg_split('/\R/', $keys) ?: [];
            if ('' === $keys || strlen($keys) > 32768 || count($lines) > 8) {
                throw new \InvalidArgumentException('Mindestens ein SSH-Public-Key ist erforderlich.');
            }
            foreach ($lines as $line) {
                if (!preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/=]+(?: .*)?$/D', trim($line))) {
                    throw new \InvalidArgumentException('Ungültiger SSH-Public-Key.');
                }
            }
        }
        $quote = $options;
        if (ShopProductKind::Gameserver === $product->getKind()) {
            $quote['game_slots'] = $variant?->getSlots() ?? $product->getGameserverSlots();
        }

        return array_replace($quote, ['domain_action' => $action, 'authinfo_hash' => hash('sha256', $authinfo), 'product_id' => $product->getId() ?? 0, 'kind' => $product->getKind()->value, 'name' => $product->getName(), 'variant' => $variant?->getName() ?? '', 'variant_id' => $variantId, 'billing_mode' => $mode, 'base_monthly_cents' => $price, 'monthly_cents' => 0 === $term ? $period - $discount : $price, 'period_cents' => $period - $discount, 'discount_cents' => $discount, 'coupon' => $couponCode, 'currency' => $this->settings->publicSettings()['currency'], 'term_months' => $term, 'slots' => $variant?->getSlots() ?? (int) ($options['slots'] ?? 32), 'hostname' => $hostname, 'ssh_keys' => $keys, 'cpu' => $cpu, 'ram' => $ram, 'disk' => $disk, 'cpu_percent' => $cpu * 100, 'ram_mib' => $ram * 1024, 'runtime_ram_mib' => \App\Module\Gameserver\Application\GameserverMemoryBudget::runtimeMiB($ram * 1024, ShopProductKind::Gameserver === $product->getKind() ? (string) ($product->getGameTemplate()?->getInstallResolver()['type'] ?? '') : ''), 'disk_mib' => $disk * 1024, 'template_id' => $product->getGameTemplate()?->getId() ?? 0, 'node_id' => $product->getProvisioningNode()?->getId() ?? '', 'port_count' => count($product->getGameTemplate()?->getRequiredPorts() ?? [])]);
    }

    /** @param array<string,int|string|bool> $quote */
    public static function digest(array $quote): string
    {
        // JSON object order can change after a database refresh (notably on MySQL).
        ksort($quote, SORT_STRING);

        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }
}
