<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Cms\Application\CmsMediaPicker;
use App\Module\Cms\Application\CmsMediaThumbnails;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Cms\UI\Http\CmsMediaThumbnailResponseFactory;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Repository\MediaAssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/admin/cms/media')]
final class AdminCmsMediaController
{
    public function __construct(
        private readonly MediaAssetRepository $mediaRepository,
        private readonly CmsSiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly ParameterBagInterface $params,
        private readonly TranslatorInterface $translator,
        private readonly CmsMediaPicker $picker,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly CmsMediaThumbnails $thumbnails,
        private readonly CmsMediaThumbnailResponseFactory $thumbnailResponse,
    ) {
    }

    #[Route(path: '/{id}/thumbnail/{variant}', name: 'admin_cms_media_thumbnail', methods: ['GET'], requirements: ['id' => '\\d+', 'variant' => 'list|picker|editor'])]
    public function thumbnail(Request $request, int $id, string $variant): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $asset = $this->ownedAsset($request, $id);
        $image = null === $asset ? null : $this->thumbnails->image($asset, $variant);
        if (null === $image) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        return $this->thumbnailResponse->create($request, $image);
    }

    #[Route(path: '/picker', name: 'admin_cms_media_picker', methods: ['GET'])]
    public function picker(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }
        $field = $request->query->getString('field');
        if (!in_array($field, ['image_path', 'hero_background_image_path', 'content', 'rich_text_content'], true)) {
            return new Response('Invalid media field.', Response::HTTP_BAD_REQUEST);
        }
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $page = max(1, min(1000, $request->query->getInt('page', 1)));
        $response = new Response($this->twig->render('admin/cms/media/_picker.html.twig', $this->picker->page($site, $search, $page) + [
            'field' => $field, 'search' => $search, 'current_page' => $page,
        ]));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route(path: '', name: 'admin_cms_media_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->renderLibrary($request, $site);
    }

    #[Route(path: '/{id}/edit', name: 'admin_cms_media_edit', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $asset = $this->ownedAsset($request, $id);
        if (null === $asset) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->renderEditor($request, $asset, $asset->getTitle() ?? '', $asset->getAlt() ?? '');
    }

    #[Route(path: '/{id}', name: 'admin_cms_media_update', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function update(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $asset = $this->ownedAsset($request, $id);
        if (null === $asset) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        if (!$this->validToken($request, 'cms_media_update_'.$id)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $metadata = $this->metadata($request);
        if ([] !== $metadata['errors']) {
            return $this->renderEditor($request, $asset, $metadata['title'], $metadata['alt'], $metadata['errors'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $asset->setTitle('' === $metadata['title'] ? null : $metadata['title']);
        $asset->setAlt('' === $metadata['alt'] ? null : $metadata['alt']);
        $this->entityManager->flush();

        return new RedirectResponse($this->libraryUrl($request, true), Response::HTTP_SEE_OTHER);
    }

    #[Route(path: '', name: 'admin_cms_media_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }
        if (!$this->validToken($request, 'cms_media_upload')) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $metadata = $this->metadata($request);
        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            $metadata['errors'][] = 'workspace_cms_library_file_error';
        }
        if ([] !== $metadata['errors']) {
            return $this->renderLibrary($request, $site, $metadata['title'], $metadata['alt'], $metadata['errors'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$upload instanceof UploadedFile) {
            throw new \LogicException('Validated upload missing.');
        }

        $ext = $upload->guessExtension() ?? 'bin';
        $filename = sprintf('cms-%s.%s', bin2hex(random_bytes(8)), $ext);
        $projectDir = $this->params->get('kernel.project_dir');
        $siteId = $site->getId();
        if (null === $siteId) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }
        $relativeDirectory = '/uploads/cms/'.$siteId;
        $targetDir = $projectDir.'/public'.$relativeDirectory;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }
        $mime = $upload->getMimeType();
        $size = $upload->getSize();
        $upload->move($targetDir, $filename);

        $asset = new MediaAsset($relativeDirectory.'/'.$filename);
        $asset->setSite($site);
        $asset->setTitle('' === $metadata['title'] ? null : $metadata['title']);
        $asset->setAlt('' === $metadata['alt'] ? null : $metadata['alt']);
        $asset->setMime($mime);
        $asset->setSize($size);

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return new RedirectResponse($this->libraryUrl($request));
    }

    #[Route(path: '/{id}/delete', name: 'admin_cms_media_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        $asset = $site instanceof Site ? $this->mediaRepository->findOneBy(['id' => $id, 'site' => $site]) : null;
        if (!$asset instanceof MediaAsset) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        if (!$this->validToken($request, 'cms_media_delete_'.$id)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }
        $projectDir = $this->params->get('kernel.project_dir');
        $absolutePath = $projectDir.'/public'.$asset->getPath();
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        $this->thumbnails->invalidate($asset);
        $this->entityManager->remove($asset);
        $this->entityManager->flush();

        return new RedirectResponse($this->libraryUrl($request));
    }

    private function ownedAsset(Request $request, int $id): ?MediaAsset
    {
        $site = $this->siteResolver->resolve($request);

        return null === $site ? null : $this->mediaRepository->findOneBy(['id' => $id, 'site' => $site]);
    }

    /** @return array{title:string,alt:string,errors:list<string>} */
    private function metadata(Request $request): array
    {
        $title = trim($request->request->getString('title'));
        $alt = trim($request->request->getString('alt'));
        $errors = [];
        if (mb_strlen($title) > 180) {
            $errors[] = 'workspace_cms_library_title_long';
        }
        if (mb_strlen($alt) > 180) {
            $errors[] = 'workspace_cms_library_alt_long';
        }

        return ['title' => $title, 'alt' => $alt, 'errors' => $errors];
    }

    private function validToken(Request $request, string $id): bool
    {
        return $this->csrf->isTokenValid(new CsrfToken($id, $request->request->getString('_token')));
    }

    /** @return array{search:string,current_page:int} */
    private function filters(Request $request): array
    {
        return ['search' => mb_substr(trim($request->query->getString('q')), 0, 100), 'current_page' => max(1, min(1000, $request->query->getInt('page', 1)))];
    }

    private function libraryUrl(Request $request, bool $saved = false): string
    {
        $filters = $this->filters($request);
        $query = [];
        if ('' !== $filters['search']) {
            $query['q'] = $filters['search'];
        }
        if ($filters['current_page'] > 1) {
            $query['page'] = $filters['current_page'];
        }
        if ($saved) {
            $query['saved'] = '1';
        }

        return '/admin/cms/media'.([] === $query ? '' : '?'.http_build_query($query));
    }

    /** @param list<string> $errors */
    private function renderLibrary(Request $request, Site $site, string $title = '', string $alt = '', array $errors = [], int $status = Response::HTTP_OK): Response
    {
        $filters = $this->filters($request);
        $rows = $this->mediaRepository->findLibraryPage($site, $filters['search'], $filters['current_page']);
        $assets = [];
        foreach (array_slice($rows, 0, 24) as $asset) {
            $assets[] = ['asset' => $asset, 'preview' => $this->picker->preview($asset)];
        }

        return new Response($this->twig->render('admin/cms/media/index.html.twig', $filters + [
            'assets' => $assets, 'has_next' => count($rows) > 24, 'upload_title' => $title, 'upload_alt' => $alt, 'errors' => $errors,
            'saved' => '1' === $request->query->getString('saved'), 'activeNav' => 'cms-media',
        ]), $status);
    }

    /** @param list<string> $errors */
    private function renderEditor(Request $request, MediaAsset $asset, string $title, string $alt, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('admin/cms/media/edit.html.twig', $this->filters($request) + [
            'asset' => $asset, 'preview' => $this->picker->preview($asset), 'title_value' => $title, 'alt_value' => $alt,
            'errors' => $errors, 'activeNav' => 'cms-media',
        ]), $status);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = RequestActor::user($request);

        return $actor instanceof User && $actor->isAdmin();
    }
}
