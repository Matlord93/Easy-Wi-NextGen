<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

interface MusicbotWebradioUrlValidatorInterface
{
    public function validate(string $url): void;
}
