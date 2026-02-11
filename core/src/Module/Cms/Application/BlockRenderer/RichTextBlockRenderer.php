<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

final class RichTextBlockRenderer extends AbstractTwigBlockRenderer
{
    public function supports(string $blockType): bool
    {
        return $blockType === 'rich_text';
    }

    public function render(array $payload): string
    {
        return $this->renderTemplate('public/blocks/v2/rich_text.html.twig', [
            'content' => (string) ($payload['content'] ?? ''),
        ]);
    }
}
