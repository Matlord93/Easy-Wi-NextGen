<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AgentRepositoryInterface;
use App\Repository\ShopCategoryRepository;
use App\Repository\ShopProductRepository;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/admin/shop/products')]
final class AdminShopProductController
{
    public function __construct(
        private readonly ShopProductRepository $productRepository,
        private readonly ShopCategoryRepository $categoryRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly AgentRepositoryInterface $agentRepository,
        private readonly SiteResolver $siteResolver,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requests,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route(path: '/table', name: 'admin_shop_products_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (null === $site) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
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
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $url = '/admin/shop/rental-products/gameserver/new';

        return new \Symfony\Component\HttpFoundation\RedirectResponse($url, 303, ['HX-Redirect' => $url]);
    }

    #[Route(path: '/{id}/edit', name: 'admin_shop_products_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if (null === $product) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if (null === $site || $product->getSiteId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $url = '/admin/shop/rental-products/'.$product->getKind()->value.'/'.$id.'/edit';

        return new \Symfony\Component\HttpFoundation\RedirectResponse($url, 303, ['HX-Redirect' => $url]);
    }

    #[Route(path: '', name: 'admin_shop_products_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->csrf->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('admin-shop', $request->request->getString('_token', $request->headers->get('X-CSRF-TOKEN', ''))))) {
            return new Response('Invalid CSRF token.', 403);
        }
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $site = $this->siteResolver->resolve($request);
        if (null === $site) {
            return new Response($this->translator->trans('error_site_not_found'), Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ([] !== $formData['errors']) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $category = $this->categoryRepository->find($formData['category_id']);
        $template = $this->templateRepository->find($formData['template_id']);
        $node = $this->agentRepository->find($formData['node_id']);

        if (null === $category || null === $template || null === $node) {
            return $this->renderFormWithErrors(array_replace($formData, [
                'errors' => ['Category, template, and node are required.'],
            ]), Response::HTTP_BAD_REQUEST);
        }

        if ($category->getSiteId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
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
            $formData['is_public_active'],
            $formData['is_customer_active'],
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
        if (!$this->csrf->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('admin-shop', $request->request->getString('_token', $request->headers->get('X-CSRF-TOKEN', ''))))) {
            return new Response('Invalid CSRF token.', 403);
        }
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if (null === $product) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if (null === $site || $product->getSiteId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        if (\App\Module\Core\Domain\Enum\ShopProductKind::Gameserver !== $product->getKind()) {
            return new Response('Bitte dieses Produkt unter /admin/shop/rental-products bearbeiten.', 409);
        }

        $formData = $this->parsePayload($request);
        if ([] !== $formData['errors']) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $product);
        }

        $category = $this->categoryRepository->find($formData['category_id']);
        $template = $this->templateRepository->find($formData['template_id']);
        $node = $this->agentRepository->find($formData['node_id']);

        if (null === $category || null === $template || null === $node) {
            return $this->renderFormWithErrors(array_replace($formData, [
                'errors' => ['Category, template, and node are required.'],
            ]), Response::HTTP_BAD_REQUEST, $product);
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
        $product->setIsPublicActive($formData['is_public_active']);
        $product->setIsCustomerActive($formData['is_customer_active']);

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
        if (!$this->csrf->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('admin-shop', $request->request->getString('_token', $request->headers->get('X-CSRF-TOKEN', ''))))) {
            return new Response('Invalid CSRF token.', 403);
        }
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $product = $this->productRepository->find($id);
        if (null === $product) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $site = $this->siteResolver->resolve($request);
        if (null === $site || $product->getSiteId() !== $site->getId()) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        if ($this->entityManager->getRepository(\App\Module\Core\Domain\Entity\ShopOrder::class)->count(['product' => $product]) > 0 || $this->entityManager->getRepository(\App\Module\Core\Domain\Entity\ShopRental::class)->count(['product' => $product]) > 0) {
            if ('true' !== $request->headers->get('HX-Request')) {
                return new \Symfony\Component\HttpFoundation\RedirectResponse('/admin/shop/rental-products/'.$product->getKind()->value.'/'.$id.'/edit?delete=blocked', 303);
            }

            return new Response('This product has orders or rentals. Deactivate it to preserve their history.', 409);
        }
        $kind = $product->getKind()->value;
        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'shop.product.deleted', [
            'product_id' => $product->getId(),
            'site_id' => $product->getSiteId(),
        ]);

        return 'true' === $request->headers->get('HX-Request') ? new Response('', Response::HTTP_NO_CONTENT) : new \Symfony\Component\HttpFoundation\RedirectResponse('/admin/shop/rental-products/'.$kind.'?deleted=1', 303);
    }

    /**
     * @return array{name: string, description: string, image_url: string|null, price_monthly_cents: int,
     * category_id: int, template_id: int, node_id: string, cpu_limit: int, ram_limit: int, disk_limit: int,
     * is_active: bool, is_public_active: bool, is_customer_active: bool, errors: list<string>}
     */
    private function parsePayload(Request $request): array
    {
        $name = trim((string) $request->request->get('name'));
        $description = trim((string) $request->request->get('description'));
        $imageUrl = trim((string) $request->request->get('image_url')) ?: null;
        $priceCents = 0;
        try {
            $priceCents = \App\Module\Core\Application\Billing\BillingAmount::parse($request->request->getString('price_monthly', '0.00'));
        } catch (\InvalidArgumentException) {
        }
        $categoryId = (int) $request->request->get('category_id', 0);
        $templateId = (int) $request->request->get('template_id', 0);
        $nodeId = (string) $request->request->get('node_id', '');
        $cpuLimit = (int) $request->request->get('cpu_limit', 0);
        $ramLimit = (int) $request->request->get('ram_limit', 0);
        $diskLimit = (int) $request->request->get('disk_limit', 0);
        $isActive = $request->request->getBoolean('is_active', false);
        $isPublicActive = $request->request->getBoolean('is_public_active', false);
        $isCustomerActive = $request->request->getBoolean('is_customer_active', false);
        $errors = [];

        if ('' === $name || mb_strlen($name) > 160) {
            $errors[] = 'Name is required.';
        }

        if ($priceCents <= 0) {
            $errors[] = 'Price per month must be positive.';
        }

        if ($categoryId <= 0 || $templateId <= 0 || '' === $nodeId) {
            $errors[] = 'Category, template, and node are required.';
        }

        if ($cpuLimit <= 0 || $cpuLimit > 21_474_836 || $ramLimit <= 0 || $ramLimit > 2_097_151 || $diskLimit <= 0 || $diskLimit > 2_097_151) {
            $errors[] = 'Limits must be positive.';
        }

        return [
            'name' => $name,
            'description' => $description,
            'image_url' => $imageUrl,
            'price_monthly_cents' => $priceCents,
            'category_id' => $categoryId,
            'template_id' => $templateId,
            'node_id' => $nodeId,
            'cpu_limit' => $cpuLimit,
            'ram_limit' => $ramLimit,
            'disk_limit' => $diskLimit,
            'is_active' => $isActive,
            'is_public_active' => $isPublicActive,
            'is_customer_active' => $isCustomerActive,
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
            'template_id' => $product?->getGameTemplate()?->getId() ?? '',
            'node_id' => $product?->getProvisioningNode()?->getId() ?? '',
            'cpu_limit' => $product?->getCpuLimit() ?? 0,
            'ram_limit' => $product?->getRamLimit() ?? 0,
            'disk_limit' => $product?->getDiskLimit() ?? 0,
            'is_active' => $product?->isActive() ?? true,
            'is_public_active' => $product?->isPublicActive() ?? true,
            'is_customer_active' => $product?->isCustomerActive() ?? true,
            'errors' => $errors,
        ];
    }

    /** @param array{errors: list<string>, ...} $formData */
    private function renderFormWithErrors(array $formData, int $status, ?ShopProduct $product = null): Response
    {
        return new Response($this->twig->render('admin/shop/products/_form.html.twig', [
            'form' => $this->buildFormContext($product, $formData['errors']),
            'categories' => $this->normalizeCategories($this->categoryRepository->findBy(['siteId' => $this->siteResolver->resolve($this->requests->getCurrentRequest() ?? Request::create('/'))?->getId() ?? 0], ['sortOrder' => 'ASC'])),
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
            'kind' => $product->getKind()->value,
            'name' => $product->getName(),
            'category' => $product->getCategory()->getName(),
            'price_monthly' => number_format($product->getPriceMonthlyCents() / 100, 2, '.', ''),
            'cpu_limit' => $product->getCpuLimit(),
            'ram_limit' => $product->getRamLimit(),
            'disk_limit' => $product->getDiskLimit(),
            'is_active' => $product->isActive(),
            'is_public_active' => $product->isPublicActive(),
            'is_customer_active' => $product->isCustomerActive(),
        ], $products);
    }

    /** @param \App\Module\Core\Domain\Entity\ShopCategory[] $categories */
    private function normalizeCategories(array $categories): array
    {
        return array_map(static fn (\App\Module\Core\Domain\Entity\ShopCategory $category): array => [
            'id' => $category->getId(),
            'name' => $category->getName(),
        ], $categories);
    }

    /** @return list<array{id: int|null, name: string}> */
    private function normalizeTemplates(): array
    {
        return array_map(static fn ($template): array => [
            'id' => $template->getId(),
            'name' => $template->getName(),
        ], $this->templateRepository->findAll());
    }

    /** @return list<array{id: string, name: string|null}> */
    private function normalizeNodes(): array
    {
        return array_map(static fn ($node): array => [
            'id' => $node->getId(),
            'name' => $node->getName(),
        ], $this->agentRepository->findAll());
    }

    private function isAdmin(Request $request): bool
    {
        $actor = RequestActor::user($request);

        return $actor instanceof User && $actor->isAdmin();
    }
}
