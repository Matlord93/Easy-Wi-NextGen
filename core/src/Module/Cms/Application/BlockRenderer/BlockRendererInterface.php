<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

interface BlockRendererInterface
{
    public function supports(string $blockType): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function render(array $payload): string;
}

