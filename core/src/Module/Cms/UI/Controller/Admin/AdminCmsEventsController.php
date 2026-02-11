<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsEvent;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Repository\CmsEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/cms/events')]
final class AdminCmsEventsController
{
    public function __construct(
        private readonly CmsEventRepository $eventRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_events_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/events/index.html.twig', [
            'events' => $this->eventRepository->findBySite($site),
            'activeNav' => 'cms-events',
        ]));
    }

    #[Route(path: '/new', name: 'admin_cms_events_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/cms/events/form.html.twig', [
            'form' => ['action_url' => '/admin/cms/events', 'event' => null],
            'activeNav' => 'cms-events',
        ]));
    }

    #[Route(path: '', name: 'admin_cms_events_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $event = new CmsEvent(
            $site,
            trim((string) $request->request->get('title', '')),
            trim((string) $request->request->get('slug', '')),
            trim((string) $request->request->get('description', '')),
            new \DateTimeImmutable((string) $request->request->get('start_at', 'now')),
        );
        $event->setEndAt($request->request->get('end_at') ? new \DateTimeImmutable((string) $request->request->get('end_at')) : null);
        $event->setLocation(trim((string) $request->request->get('location', '')) ?: null);
        $event->setStatus(trim((string) $request->request->get('status', 'planned')));
        $event->setCoverImagePath(trim((string) $request->request->get('cover_image_path', '')) ?: null);
        $event->setPublished($request->request->get('is_published') === 'on');

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/events');
    }

    #[Route(path: '/{id}/edit', name: 'admin_cms_events_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $event = $this->eventRepository->find($id);
        if (!$event instanceof CmsEvent) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/events/form.html.twig', [
            'form' => ['action_url' => '/admin/cms/events/' . $id, 'event' => $event],
            'activeNav' => 'cms-events',
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_cms_events_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $event = $this->eventRepository->find($id);
        if (!$event instanceof CmsEvent) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $event->setTitle((string) $request->request->get('title', ''));
        $event->setSlug((string) $request->request->get('slug', ''));
        $event->setDescription((string) $request->request->get('description', ''));
        $event->setStartAt(new \DateTimeImmutable((string) $request->request->get('start_at', 'now')));
        $event->setEndAt($request->request->get('end_at') ? new \DateTimeImmutable((string) $request->request->get('end_at')) : null);
        $event->setLocation(trim((string) $request->request->get('location', '')) ?: null);
        $event->setStatus((string) $request->request->get('status', 'planned'));
        $event->setCoverImagePath(trim((string) $request->request->get('cover_image_path', '')) ?: null);
        $event->setPublished($request->request->get('is_published') === 'on');

        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/events');
    }

    #[Route(path: '/{id}/delete', name: 'admin_cms_events_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $event = $this->eventRepository->find($id);
        if ($event instanceof CmsEvent) {
            $this->entityManager->remove($event);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/events');
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
