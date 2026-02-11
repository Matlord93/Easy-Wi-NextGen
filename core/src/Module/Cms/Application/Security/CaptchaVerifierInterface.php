<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\Security;

use Symfony\Component\HttpFoundation\Request;

interface CaptchaVerifierInterface
{
    public function verify(Request $request, string $context): bool;
}
