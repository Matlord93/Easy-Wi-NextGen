<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

final class CardsBlockRenderer extends AbstractTwigBlockRenderer
{
    public function supports(string $blockType): bool
    {
        return $blockType === 'cards';
    }

    public function render(array $payload): string
    {
        $cards = [];
        foreach (($payload['cards'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $cards[] = [
                'title' => (string) ($entry['title'] ?? ''),
                'text' => (string) ($entry['text'] ?? ''),
                'icon' => (string) ($entry['icon'] ?? ''),
                'url' => (string) ($entry['url'] ?? ''),
            ];
        }

        return $this->renderTemplate('public/blocks/v2/cards.html.twig', [
            'cards' => $cards,
        ]);
    }
}
