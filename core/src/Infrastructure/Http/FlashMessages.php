<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class FlashMessages
{
    public static function add(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new \LogicException('The configured session must support flash messages.');
        }

        $session->getFlashBag()->add($type, $message);
    }
}
