<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use App\Repository\AgentRepository;
use App\Repository\ShopCategoryRepository;
use App\Repository\ShopProductRepository;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/shop/products')]
final class AdminShopProductController
{
    public function __construct(
        private readonly ShopProductRepository $productRepository,
        private readonly ShopCategoryRepository $categoryRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly AgentRepository $agentRepository,
        private readonly SiteResolver $siteResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_shop_products', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $products = $this->productRepository->findBy(['siteId' => $site->getId()], ['createdAt' => 'DESC']);
        $categories = $this->categoryRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']);

        return new Response($this->twig->render('admin/shop/products/index.html.twig', [
            'products' => $this->normalizeProducts($products),
            'categories' => $this->normalizeCategories($categories),
            'templates' => $this->normalizeTemplates(),
            'nodes' => $this->normalizeNodes(),
            'form' => $this->buildFormContext(),
            'activeNav' => 'shop-products',
        ]));
    }

    #[Route(path: '/table', name: 'admin_shop_products_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $products = $this->productRepository->findBy(['siteId' => $site->getId()], ['createdAt' => 'DESC']);

        return new Response($this->twig->render('admin/shop/products/_table.html.twig', [
            'products' => $this->normalizeProducts($products),
        ]));
    }

    #[Route(path: '/form', name: 'admin_shop_products_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/shop/products/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'categories' => $this->normalizeCategories($this->categoryRepository->findAll()),
            'templates' => $this->normalizeTemplates(),
            'nodes' => $this->normalizeNodes(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_shop_products_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if ($product === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $product->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/shop/products/_form.html.twig', [
            'form' => $this->buildFormContext($product),
            'categories' => $this->normalizeCategories($this->categoryRepository->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC'])),
            'templates' => $this->normalizeTemplates(),
            'nodes' => $this->normalizeNodes(),
        ]));
    }

    #[Route(path: '', name: 'admin_shop_products_create', methods: ['POST'])]
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

        $category = $this->categoryRepository->find($formData['category_id']);
        $template = $this->templateRepository->find($formData['template_id']);
        $node = $this->agentRepository->find($formData['node_id']);

        if ($category === null || $template === null || $node === null) {
            return $this->renderFormWithErrors([
                ...$formData,
                'errors' => ['Category, template, and node are required.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($category->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $product = new ShopProduct(
            $site->getId() ?? 0,
            $category,
            $formData['name'],
            $formData['description'],
            $formData['price_monthly_cents'],
            $template,
            $node,
            $formData['cpu_limit'],
            $formData['ram_limit'],
            $formData['disk_limit'],
            $formData['image_url'],
            $formData['is_active'],
        );

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'shop.product.created', [
            'product_id' => $product->getId(),
            'site_id' => $product->getSiteId(),
        ]);

        return $this->renderFormSuccess();
    }

    #[Route(path: '/{id}', name: 'admin_shop_products_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if ($product === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $product->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $product);
        }

        $category = $this->categoryRepository->find($formData['category_id']);
        $template = $this->templateRepository->find($formData['template_id']);
        $node = $this->agentRepository->find($formData['node_id']);

        if ($category === null || $template === null || $node === null) {
            return $this->renderFormWithErrors([
                ...$formData,
                'errors' => ['Category, template, and node are required.'],
            ], Response::HTTP_BAD_REQUEST, $product);
        }

        $product->setCategory($category);
        $product->setName($formData['name']);
        $product->setDescription($formData['description']);
        $product->setImageUrl($formData['image_url']);
        $product->setPriceMonthlyCents($formData['price_monthly_cents']);
        $product->setTemplate($template);
        $product->setNode($node);
        $product->setCpuLimit($formData['cpu_limit']);
        $product->setRamLimit($formData['ram_limit']);
        $product->setDiskLimit($formData['disk_limit']);
        $product->setIsActive($formData['is_active']);

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'shop.product.updated', [
            'product_id' => $product->getId(),
            'site_id' => $product->getSiteId(),
        ]);

        return $this->renderFormSuccess();
    }

    #[Route(path: '/{id}/delete', name: 'admin_shop_products_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if ($product === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null || $product->getSiteId() !== $site->getId()) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'shop.product.deleted', [
            'product_id' => $product->getId(),
            'site_id' => $product->getSiteId(),
        ]);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function parsePayload(Request $request): array
    {
        $name = trim((string) $request->request->get('name'));
        $description = trim((string) $request->request->get('description'));
        $imageUrl = trim((string) $request->request->get('image_url')) ?: null;
        $priceMonthly = (float) $request->request->get('price_monthly', 0);
        $categoryId = (int) $request->request->get('category_id', 0);
        $templateId = (int) $request->request->get('template_id', 0);
        $nodeId = (string) $request->request->get('node_id', '');
        $cpuLimit = (int) $request->request->get('cpu_limit', 0);
        $ramLimit = (int) $request->request->get('ram_limit', 0);
        $diskLimit = (int) $request->request->get('disk_limit', 0);
        $isActive = $request->request->getBoolean('is_active', true);
        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($description === '') {
            $errors[] = 'Description is required.';
        }

        if ($priceMonthly <= 0) {
            $errors[] = 'Price per month must be positive.';
        }

        if ($categoryId <= 0 || $templateId <= 0 || $nodeId === '') {
            $errors[] = 'Category, template, and node are required.';
        }

        if ($cpuLimit <= 0 || $ramLimit <= 0 || $diskLimit <= 0) {
            $errors[] = 'Limits must be positive.';
        }

        return [
            'name' => $name,
            'description' => $description,
            'image_url' => $imageUrl,
            'price_monthly_cents' => (int) round($priceMonthly * 100),
            'category_id' => $categoryId,
            'template_id' => $templateId,
            'node_id' => $nodeId,
            'cpu_limit' => $cpuLimit,
            'ram_limit' => $ramLimit,
            'disk_limit' => $diskLimit,
            'is_active' => $isActive,
            'errors' => $errors,
        ];
    }

    private function buildFormContext(?ShopProduct $product = null, array $errors = []): array
    {
        return [
            'action' => $product ? 'update' : 'create',
            'id' => $product?->getId(),
            'name' => $product?->getName() ?? '',
            'description' => $product?->getDescription() ?? '',
            'image_url' => $product?->getImageUrl() ?? '',
            'price_monthly' => $product ? number_format($product->getPriceMonthlyCents() / 100, 2, '.', '') : '',
            'category_id' => $product?->getCategory()->getId() ?? '',
            'template_id' => $product?->getTemplate()->getId() ?? '',
            'node_id' => $product?->getNode()->getId() ?? '',
            'cpu_limit' => $product?->getCpuLimit() ?? 0,
            'ram_limit' => $product?->getRamLimit() ?? 0,
            'disk_limit' => $product?->getDiskLimit() ?? 0,
            'is_active' => $product?->isActive() ?? true,
            'errors' => $errors,
        ];
    }

    private function renderFormWithErrors(array $formData, int $status, ?ShopProduct $product = null): Response
    {
        return new Response($this->twig->render('admin/shop/products/_form.html.twig', [
            'form' => $this->buildFormContext($product, $formData['errors']),
            'categories' => $this->normalizeCategories($this->categoryRepository->findAll()),
            'templates' => $this->normalizeTemplates(),
            'nodes' => $this->normalizeNodes(),
        ]), $status);
    }

    private function renderFormSuccess(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT, [
            'HX-Trigger' => 'shop-products-changed',
        ]);
    }

    /**
     * @param ShopProduct[] $products
     */
    private function normalizeProducts(array $products): array
    {
        return array_map(static fn (ShopProduct $product): array => [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'category' => $product->getCategory()->getName(),
            'price_monthly' => number_format($product->getPriceMonthlyCents() / 100, 2, '.', ''),
            'cpu_limit' => $product->getCpuLimit(),
            'ram_limit' => $product->getRamLimit(),
            'disk_limit' => $product->getDiskLimit(),
            'is_active' => $product->isActive(),
        ], $products);
    }

    private function normalizeCategories(array $categories): array
    {
        return array_map(static fn ($category): array => [
            'id' => $category->getId(),
            'name' => $category->getName(),
        ], $categories);
    }

    private function normalizeTemplates(): array
    {
        return array_map(static fn ($template): array => [
            'id' => $template->getId(),
            'name' => $template->getName(),
        ], $this->templateRepository->findAll());
    }

    private function normalizeNodes(): array
    {
        return array_map(static fn ($node): array => [
            'id' => $node->getId(),
            'name' => $node->getName(),
        ], $this->agentRepository->findAll());
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }
}
