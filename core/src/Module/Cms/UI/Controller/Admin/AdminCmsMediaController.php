<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\SiteResolver;
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
use Twig\Environment;

#[Route(path: '/admin/cms/media')]
final class AdminCmsMediaController
{
    public function __construct(
        private readonly MediaAssetRepository $mediaRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly ParameterBagInterface $params,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_media_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);

        return new Response($this->twig->render('admin/cms/media/index.html.twig', [
            'assets' => $this->mediaRepository->findBySite($site instanceof Site ? $site : null),
            'activeNav' => 'cms-media',
        ]));
    }

    #[Route(path: '', name: 'admin_cms_media_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);

        $upload = $request->files->get('file');
        if (!$upload instanceof UploadedFile) {
            return new RedirectResponse('/admin/cms/media');
        }

        $ext = $upload->guessExtension() ?: 'bin';
        $filename = sprintf('cms-%s.%s', bin2hex(random_bytes(8)), $ext);
        $projectDir = (string) $this->params->get('kernel.project_dir');
        $targetDir = $projectDir . '/public/uploads';
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }
        $upload->move($targetDir, $filename);

        $asset = new MediaAsset('/uploads/' . $filename);
        if ($site instanceof Site) {
            $asset->setSite($site);
        }
        $asset->setTitle(trim((string) $request->request->get('title', '')) ?: null);
        $asset->setAlt(trim((string) $request->request->get('alt', '')) ?: null);
        $asset->setMime($upload->getMimeType());
        $asset->setSize($upload->getSize());

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/media');
    }

    #[Route(path: '/{id}/delete', name: 'admin_cms_media_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $asset = $this->mediaRepository->find($id);
        if ($asset instanceof MediaAsset) {
            $projectDir = (string) $this->params->get('kernel.project_dir');
            $absolutePath = $projectDir . '/public' . $asset->getPath();
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            $this->entityManager->remove($asset);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/media');
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
