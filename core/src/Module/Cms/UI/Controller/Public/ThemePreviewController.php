<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Http\CmsPreviewResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ThemePreviewController
{
    public function __construct(
        private readonly ThemeResolver $themeResolver,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly CmsPreviewResponseFactory $responses,
    ) {
    }

    #[Route(path: '/preview/{theme}', name: 'public_theme_preview', methods: ['GET'])]
    public function __invoke(Request $request, string $theme): Response
    {
        if (!in_array($theme, $this->themeResolver->supportedThemes(), true)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set('_cms_preview', true);
        $request->attributes->set('_cms_preview_theme', $theme);

        return $this->responses->create($this->twig->render(sprintf('themes/%s/pages/startseite.html.twig', $theme), [
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
        ]), $request);
    }
}
