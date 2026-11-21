<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\ShopProduct;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class CheckoutSelection
{
    private const SESSION_KEY = 'rental_checkout_selection';

    public function __construct(private SecretsCryptoInterface $crypto, private CacheItemPoolInterface $cache)
    {
    }

    /** @param array<string, string> $input */
    public function save(Request $request, ShopProduct $product, bool $public, array $input): string
    {
        $key = bin2hex(random_bytes(16));
        $saved = array_intersect_key($input, array_flip(['variant_id', 'billing_mode', 'hostname', 'ssh_keys', 'coupon', 'domain_action']));
        if ('' !== ($input['auth_code'] ?? '')) {
            $saved['auth_code_encrypted'] = $this->crypto->encrypt($input['auth_code']);
        }
        $path = ($public ? '/shop/products/' : '/marketplace/contracts/new/').($product->getId() ?? 0);
        $target = $path.'?intent='.$key;
        $request->getSession()->set(self::SESSION_KEY, [
            'target' => $target, 'site' => $product->getSiteId(), 'expires' => time() + 86400, 'options' => $saved,
        ]);

        return $target;
    }

    /** @return array<string, string>|null */
    public function options(Request $request, ShopProduct $product, bool $public, string $key): ?array
    {
        $target = ($public ? '/shop/products/' : '/marketplace/contracts/new/').($product->getId() ?? 0).'?intent='.$key;
        $saved = $this->resolve($request, $target);
        if (null === $saved || ($saved['site'] ?? null) !== $product->getSiteId()) {
            return null;
        }
        $options = JsonMap::strings($saved['options'] ?? null);
        if (isset($options['auth_code_encrypted'])) {
            try {
                $options['auth_code'] = $this->crypto->decrypt($options['auth_code_encrypted']);
            } catch (\RuntimeException) {
                return null;
            }
            unset($options['auth_code_encrypted']);
        }

        return $options;
    }

    public function registrationTarget(Request $request, mixed $target): ?string
    {
        if (!is_string($target) || !preg_match('~^/shop/products/[1-9][0-9]*\?intent=[a-f0-9]{32}$~D', $target) || null === $this->resolve($request, $target)) {
            return null;
        }

        return $target;
    }

    public function forget(Request $request, string $target): void
    {
        if (null !== $this->resolve($request, $target)) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }

    public function holdForVerification(Request $request, string $tokenHash, string $target): void
    {
        $saved = $this->resolve($request, $target);
        if (null === $saved) {
            return;
        }
        $item = $this->cache->getItem('registration.checkout.'.$tokenHash);
        $item->set($saved)->expiresAfter(86400);
        $this->cache->save($item);
    }

    public function restoreAfterVerification(Request $request, string $tokenHash, string $target): void
    {
        $key = 'registration.checkout.'.$tokenHash;
        $saved = JsonMap::fromValue($this->cache->getItem($key)->get());
        if (null !== $saved && ($saved['target'] ?? null) === $target && is_int($saved['expires'] ?? null) && $saved['expires'] > time()) {
            $request->getSession()->set(self::SESSION_KEY, $saved);
        }
        $this->cache->deleteItem($key);
    }

    /** @return array<string, mixed>|null */
    private function resolve(Request $request, string $target): ?array
    {
        $saved = JsonMap::fromValue($request->getSession()->get(self::SESSION_KEY));
        if (null === $saved || ($saved['target'] ?? null) !== $target || !is_int($saved['expires'] ?? null) || $saved['expires'] <= time()) {
            return null;
        }

        return $saved;
    }
}
