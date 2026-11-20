<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Cms\Application\CmsContentCheck;
use App\Module\Cms\Application\PageResolver;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Controller\Public\PublicCmsPageController;
use App\Module\Cms\UI\Http\CmsPreviewResponseFactory;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\User;
use App\Repository\CmsPageRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/admin/cms/pages/{id}/preview', requirements: ['id' => '\d+'])]
final readonly class AdminCmsPreviewController
{
    public function __construct(
        private CmsPageRepository $pages,
        private CmsSiteResolver $sites,
        private ThemeResolver $themes,
        private PageResolver $pageResolver,
        private PublicCmsPageController $renderer,
        private CmsPreviewResponseFactory $responses,
        private Environment $twig,
        private CmsContentCheck $contentCheck,
    ) {
    }

    #[Route('', name: 'admin_cms_page_preview', methods: ['GET'])]
    public function index(Request $request, int $id): Response
    {
        return $this->preview($request, $id, false);
    }

    #[Route('/content', name: 'admin_cms_page_preview_content', methods: ['GET'])]
    public function content(Request $request, int $id): Response
    {
        return $this->preview($request, $id, true);
    }

    private function preview(Request $request, int $id, bool $content): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $site = $this->sites->resolve($request);
        $page = null === $site ? null : $this->pages->findOneBy(['id' => $id, 'site' => $site]);
        if (!$page instanceof CmsPage) {
            return new Response('Not found.', 404);
        }
        $theme = $request->query->getString('theme', $this->themes->resolveThemeKey($page->getSite()));
        $scheme = $request->query->getString('scheme', 'dark');
        $viewport = $request->query->getString('viewport', 'desktop');
        if (!in_array($theme, $this->themes->supportedThemes(), true) || !in_array($scheme, ['light', 'dark'], true) || !in_array($viewport, ['desktop', 'mobile'], true)) {
            return new Response('Invalid preview selection.', 400);
        }
        if ($content) {
            $request->attributes->set('_cms_preview', true);
            $request->attributes->set('_cms_preview_theme', $theme);
            $html = (string) $this->renderer->renderForPreview($page, $theme)->getContent();

            return $this->responses->create($html, $request, $scheme);
        }

        $response = new Response($this->twig->render('admin/cms/pages/preview.html.twig', [
            'page' => $page,
            'preview_theme' => $theme,
            'preview_scheme' => $scheme,
            'preview_viewport' => $viewport,
            'supported_themes' => $this->themes->supportedThemes(),
            'module_route' => $this->pageResolver->isReservedSlug($page->getSlug()),
            'content_check' => $this->contentCheck->inspect($page),
            'activeNav' => 'cms-pages',
        ]));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
