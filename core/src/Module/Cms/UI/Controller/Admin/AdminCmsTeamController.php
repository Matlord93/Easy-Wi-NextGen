<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Admin;

use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\TeamMember;
use App\Module\Core\Domain\Entity\User;
use App\Repository\TeamGroupRepository;
use App\Repository\TeamMemberRepository;
use App\Module\Core\Domain\Entity\TeamGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/cms/team')]
final class AdminCmsTeamController
{
    public function __construct(
        private readonly TeamMemberRepository $teamRepository,
        private readonly TeamGroupRepository $teamGroupRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_cms_team_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/team/index.html.twig', [
            'members' => $this->teamRepository->findBySite($site),
            'teamGroups' => $this->teamGroupRepository->findBySite($site),
            'activeNav' => 'cms-team',
        ]));
    }

    #[Route(path: '/new', name: 'admin_cms_team_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/team/form.html.twig', [
            'form' => ['action_url' => '/admin/cms/team', 'member' => null],
            'teamGroups' => $this->teamGroupRepository->findBySite($site),
            'activeNav' => 'cms-team',
        ]));
    }

    #[Route(path: '', name: 'admin_cms_team_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $member = new TeamMember(
            $site,
            trim((string) $request->request->get('name', '')),
            trim((string) $request->request->get('role_title', '')),
        );
        $member->setTeamName((string) $request->request->get('team_name', ''));
        $member->setBio((string) $request->request->get('bio', ''));
        $member->setAvatarPath(trim((string) $request->request->get('avatar_path', '')) ?: null);
        $member->setSortOrder((int) $request->request->get('sort_order', 0));
        $member->setActive($request->request->get('is_active') !== '0');

        $socialsRaw = trim((string) $request->request->get('socials_json', ''));
        $socials = $socialsRaw !== '' ? json_decode($socialsRaw, true) : null;
        $member->setSocialsJson(is_array($socials) ? $socials : null);

        $this->entityManager->persist($member);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/team');
    }

    #[Route(path: '/{id}/edit', name: 'admin_cms_team_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $member = $this->teamRepository->find($id);
        if (!$member instanceof TeamMember) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/cms/team/form.html.twig', [
            'form' => ['action_url' => '/admin/cms/team/' . $id, 'member' => $member],
            'teamGroups' => $this->teamGroupRepository->findBySite($member->getSite()),
            'activeNav' => 'cms-team',
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_cms_team_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $member = $this->teamRepository->find($id);
        if (!$member instanceof TeamMember) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $member->setName((string) $request->request->get('name', ''));
        $member->setRoleTitle((string) $request->request->get('role_title', ''));
        $member->setTeamName((string) $request->request->get('team_name', ''));
        $member->setBio((string) $request->request->get('bio', ''));
        $member->setAvatarPath(trim((string) $request->request->get('avatar_path', '')) ?: null);
        $member->setSortOrder((int) $request->request->get('sort_order', 0));
        $member->setActive($request->request->get('is_active') !== '0');
        $socialsRaw = trim((string) $request->request->get('socials_json', ''));
        $socials = $socialsRaw !== '' ? json_decode($socialsRaw, true) : null;
        $member->setSocialsJson(is_array($socials) ? $socials : null);

        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/team');
    }

    #[Route(path: '/{id}/delete', name: 'admin_cms_team_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $member = $this->teamRepository->find($id);
        if ($member instanceof TeamMember) {
            $this->entityManager->remove($member);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/team');
    }


    #[Route(path: '/groups', name: 'admin_cms_team_group_create', methods: ['POST'])]
    public function createGroup(Request $request): Response
    {
        if (!$this->isAdmin($request)) { return new Response('Forbidden.', Response::HTTP_FORBIDDEN); }
        $site = $this->siteResolver->resolve($request);
        if (!$site instanceof Site) { return new Response('Site not found.', Response::HTTP_NOT_FOUND); }

        $name = trim((string) $request->request->get('name', ''));
        if ($name !== '') {
            $slug = strtolower(trim((string) $request->request->get('slug', '')));
            if ($slug === '') {
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            }
            $group = new TeamGroup($site, $name, trim((string) $request->request->get('game', '')), $slug);
            $group->setImagePath(trim((string) $request->request->get('image_path', '')) ?: null);
            $group->setSortOrder((int) $request->request->get('sort_order', 0));
            $this->entityManager->persist($group);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/admin/cms/team');
    }

    #[Route(path: '/groups/{id}', name: 'admin_cms_team_group_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateGroup(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) { return new Response('Forbidden.', Response::HTTP_FORBIDDEN); }

        $group = $this->teamGroupRepository->find($id);
        if (!$group instanceof TeamGroup) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            return new RedirectResponse('/admin/cms/team');
        }

        $slug = strtolower(trim((string) $request->request->get('slug', '')));
        if ($slug === '') {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        }

        $group->setName($name);
        $group->setGame(trim((string) $request->request->get('game', '')));
        $group->setSlug($slug);
        $group->setImagePath(trim((string) $request->request->get('image_path', '')) ?: null);
        $group->setSortOrder((int) $request->request->get('sort_order', 0));

        $this->entityManager->flush();

        return new RedirectResponse('/admin/cms/team');
    }

    #[Route(path: '/groups/{id}/delete', name: 'admin_cms_team_group_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteGroup(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) { return new Response('Forbidden.', Response::HTTP_FORBIDDEN); }
        $group = $this->teamGroupRepository->find($id);
        if ($group instanceof TeamGroup) {
            $this->entityManager->remove($group);
            $this->entityManager->flush();
        }
        return new RedirectResponse('/admin/cms/team');
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
