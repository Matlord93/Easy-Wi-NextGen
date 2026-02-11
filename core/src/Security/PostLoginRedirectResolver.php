<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Symfony\Component\HttpFoundation\Request;

final class PostLoginRedirectResolver
{
    public const SESSION_TARGET_KEY = 'auth_intended_path';

    public function resolve(User $user, Request $request): string
    {
        $session = $request->getSession();
        $candidates = [
            $request->request->get('target'),
            $request->query->get('target'),
            $session->get(self::SESSION_TARGET_KEY),
            $session->get('auth_pending_target_path'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if ($this->isSafeTarget($user, $candidate)) {
                $session->remove(self::SESSION_TARGET_KEY);

                return $candidate;
            }
        }

        $session->remove(self::SESSION_TARGET_KEY);

        return match ($user->getType()) {
            UserType::Admin, UserType::Superadmin => '/admin',
            UserType::Reseller => '/reseller',
            UserType::Customer => $user->isCustomerAccessEnabled() ? '/customer' : '/',
            default => '/',
        };
    }

    public function isSafeTarget(User $user, string $target): bool
    {
        if ($target === '' || !str_starts_with($target, '/')) {
            return false;
        }

        if (str_starts_with($target, '//') || str_contains($target, "\r") || str_contains($target, "\n")) {
            return false;
        }

        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        $forbiddenPrefixes = ['/login', '/2fa', '/2fa_check', '/system/recovery'];
        foreach ($forbiddenPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        if (str_starts_with($path, '/admin') && !$user->isAdmin()) {
            return false;
        }

        if (str_starts_with($path, '/reseller') && $user->getType() !== UserType::Reseller) {
            return false;
        }

        if (str_starts_with($path, '/customer') && (!$user->isCustomer() || !$user->isCustomerAccessEnabled())) {
            return false;
        }

        if ($user->isAdmin() && (str_starts_with($path, '/reseller') || str_starts_with($path, '/customer'))) {
            return false;
        }

        if ($user->getType() === UserType::Reseller && str_starts_with($path, '/customer')) {
            return false;
        }

        return true;
    }
}
