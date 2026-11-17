<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Webhook;

interface WebhookUrlValidatorInterface
{
    public function validate(string $url): string;
}
