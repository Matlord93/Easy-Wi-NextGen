<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use App\Repository\ShopCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/shop/categories')]
final class AdminShopCategoryController
{
    public function __construct(
        private readonly ShopCategoryRepository $categoryRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_shop_categories', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $categories = $this->categoryRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/shop/categories/index.html.twig', [
            'categories' => $this->normalizeCategories($categories),
            'form' => $this->buildFormContext(),
            'activeNav' => 'shop-categories',
        ]));
    }

    #[Route(path: '/table', name: 'admin_shop_categories_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $categories = $this->categoryRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/shop/categories/_table.html.twig', [
            'categories' => $this->normalizeCategories($categories),
        ]));
    }

    #[Route(path: '/form', name: 'admin_shop_categories_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/shop/categories/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_shop_categories_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepository->find($id);
        if ($category === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $category->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/shop/categories/_form.html.twig', [
            'form' => $this->buildFormContext($category),
        ]));
    }

    #[Route(path: '', name: 'admin_shop_categories_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $category = new ShopCategory(
            $site->getId() ?? 0,
            $formData['name'],
            $formData['slug'],
            $formData['sort_order'],
        );

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'shop.category.created', [
            'category_id' => $category->getId(),
            'site_id' => $category->getSiteId(),
        ]);

        return $this->renderFormSuccess();
    }

    #[Route(path: '/{id}', name: 'admin_shop_categories_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepository->find($id);
        if ($category === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $category->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $category);
        }

        $category->setName($formData['name']);
        $category->setSlug($formData['slug']);
        $category->setSortOrder($formData['sort_order']);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'shop.category.updated', [
            'category_id' => $category->getId(),
            'site_id' => $category->getSiteId(),
        ]);

        return $this->renderFormSuccess();
    }

    #[Route(path: '/{id}/delete', name: 'admin_shop_categories_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $category = $this->categoryRepository->find($id);
        if ($category === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $category->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'shop.category.deleted', [
            'category_id' => $category->getId(),
            'site_id' => $category->getSiteId(),
        ]);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function parsePayload(Request $request): array
    {
        $name = trim((string) $request->request->get('name'));
        $slug = trim((string) $request->request->get('slug'));
        $sortOrder = (int) $request->request->get('sort_order', 0);
        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($slug === '') {
            $slug = $this->slugify($name);
        }

        if ($slug === '') {
            $errors[] = 'Slug is required.';
        }

        return [
            'name' => $name,
            'slug' => $slug,
            'sort_order' => $sortOrder,
            'errors' => $errors,
        ];
    }

    private function buildFormContext(?ShopCategory $category = null, array $errors = []): array
    {
        return [
            'action' => $category ? 'update' : 'create',
            'id' => $category?->getId(),
            'name' => $category?->getName() ?? '',
            'slug' => $category?->getSlug() ?? '',
            'sort_order' => $category?->getSortOrder() ?? 0,
            'errors' => $errors,
        ];
    }

    private function renderFormWithErrors(array $formData, int $status, ?ShopCategory $category = null): Response
    {
        return new Response($this->twig->render('admin/shop/categories/_form.html.twig', [
            'form' => $this->buildFormContext($category, $formData['errors']),
        ]), $status);
    }

    private function renderFormSuccess(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT, [
            'HX-Trigger' => 'shop-categories-changed',
        ]);
    }

    /**
     * @param ShopCategory[] $categories
     */
    private function normalizeCategories(array $categories): array
    {
        return array_map(static fn (ShopCategory $category): array => [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'sort_order' => $category->getSortOrder(),
        ], $categories);
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }
}
