<?php

declare(strict_types=1);

namespace App\Module\Cms\Application\BlockRenderer;

use Twig\Environment;

abstract class AbstractTwigBlockRenderer implements BlockRendererInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function renderTemplate(string $template, array $payload): string
    {
        return $this->twig->render($template, [
            'payload' => $payload,
        ]);
    }
}
