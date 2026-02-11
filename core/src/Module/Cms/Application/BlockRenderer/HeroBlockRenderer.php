<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

final class HeroBlockRenderer extends AbstractTwigBlockRenderer
{
    public function supports(string $blockType): bool
    {
        return $blockType === 'hero';
    }

    public function render(array $payload): string
    {
        return $this->renderTemplate('public/blocks/v2/hero.html.twig', [
            'headline' => (string) ($payload['headline'] ?? ''),
            'subheadline' => (string) ($payload['subheadline'] ?? ''),
            'backgroundImagePath' => (string) ($payload['backgroundImagePath'] ?? ''),
            'ctaText' => (string) ($payload['ctaText'] ?? ''),
            'ctaUrl' => (string) ($payload['ctaUrl'] ?? ''),
        ]);
    }
}

