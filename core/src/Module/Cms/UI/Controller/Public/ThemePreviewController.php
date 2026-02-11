<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class ThemePreviewController
{
    public function __construct(
        private readonly ThemeResolver $themeResolver,
        private readonly Environment $twig,
        #[Autowire('%kernel.debug%')]
        private readonly bool $kernelDebug,
    ) {
    }

    #[Route(path: '/preview/{theme}', name: 'public_theme_preview', methods: ['GET'])]
    public function __invoke(Request $request, string $theme): Response
    {
        $user = $request->attributes->get('current_user');
        if (!$this->kernelDebug && (!$user instanceof User || !$user->isAdmin())) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!in_array($theme, $this->themeResolver->supportedThemes(), true)) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render(sprintf('themes/%s/pages/startseite.html.twig', $theme), [
            'page' => ['title' => 'Theme Preview', 'slug' => 'startseite'],
            'cms_pages' => [
                ['title' => 'Startseite', 'slug' => 'startseite', 'is_active' => true],
                ['title' => 'Blog', 'slug' => 'blog', 'is_active' => false],
                ['title' => 'Events', 'slug' => 'events', 'is_active' => false],
            ],
            'blocks' => [
                ['type' => 'text', 'html' => '<h2>Preview Hero</h2><p>Dummy content for theme preview.</p>', 'servers' => [], 'settings' => []],
                ['type' => 'text', 'html' => '<p>This preview ignores CMS settings and forces selected theme.</p>', 'servers' => [], 'settings' => []],
            ],
            'active_theme' => $theme,
            'template_key' => $theme,
        ]));
    }
}
