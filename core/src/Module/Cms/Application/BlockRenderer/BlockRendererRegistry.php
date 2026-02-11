<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

use App\Module\Core\Domain\Entity\CmsBlock;
use Twig\Environment;

final class BlockRendererRegistry
{
    /**
     * @param iterable<BlockRendererInterface> $renderers
     */
    public function __construct(
        private readonly iterable $renderers,
        private readonly Environment $twig,
    ) {
    }

    public function render(CmsBlock $block): string
    {
        if ($block->getVersion() === 2 && is_array($block->getPayloadJson())) {
            foreach ($this->renderers as $renderer) {
                if ($renderer->supports($block->getType())) {
                    return $renderer->render($block->getPayloadJson() ?? []);
                }
            }
        }

        return $this->twig->render('public/blocks/legacy/default.html.twig', [
            'content' => $block->getContent(),
        ]);
    }
}

