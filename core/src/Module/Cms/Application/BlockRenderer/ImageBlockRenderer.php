<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

final class ImageBlockRenderer extends AbstractTwigBlockRenderer
{
    public function supports(string $blockType): bool
    {
        return $blockType === 'image';
    }

    public function render(array $payload): string
    {
        return $this->renderTemplate('public/blocks/v2/image.html.twig', [
            'path' => (string) ($payload['path'] ?? ''),
            'alt' => (string) ($payload['alt'] ?? ''),
            'caption' => (string) ($payload['caption'] ?? ''),
        ]);
    }
}
