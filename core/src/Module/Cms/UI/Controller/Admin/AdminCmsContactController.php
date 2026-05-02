<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ContactMessage;
use App\Module\Core\Domain\Entity\User;
use App\Repository\ContactMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/cms/contact')]
final class AdminCmsContactController
{
    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly ContactMessageRepository $contactMessageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_contact', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $messages = $this->contactMessageRepository->findBySite($site, $perPage, $offset);
        $total = $this->contactMessageRepository->countBySite($site);
        $newCount = $this->contactMessageRepository->countNewBySite($site);

        return new Response($this->twig->render('admin/cms/contact/index.html.twig', [
            'activeNav' => 'cms-contact',
            'messages' => $messages,
            'total' => $total,
            'new_count' => $newCount,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_cms_contact_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $message = $this->contactMessageRepository->find($id);
        if (!$message instanceof ContactMessage || $message->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $message->markRead();
        $this->entityManager->flush();

        return new Response($this->twig->render('admin/cms/contact/show.html.twig', [
            'activeNav' => 'cms-contact',
            'message' => $message,
            'replied' => $request->query->get('replied') === '1',
        ]));
    }

    #[Route(path: '/{id}/reply', name: 'admin_cms_contact_reply', methods: ['POST'])]
    public function reply(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $message = $this->contactMessageRepository->find($id);
        if (!$message instanceof ContactMessage || $message->getSite()->getId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $replyText = trim((string) $request->request->get('reply_text', ''));
        if ($replyText !== '') {
            $message->reply($replyText);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/contact/' . $id . '?replied=1');
    }

    #[Route(path: '/{id}/delete', name: 'admin_cms_contact_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $message = $this->contactMessageRepository->find($id);
        if ($message instanceof ContactMessage && $message->getSite()->getId() === $site->getId()) {
            $this->entityManager->remove($message);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/contact');
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
