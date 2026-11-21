<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Billing\BillingAmount;
use App\Module\Core\Application\Contracts\RentalCapacity;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\RentalCapacityPool;
use App\Module\Core\Domain\Entity\ShopCategory;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class AdminRentalProductController
{
    private const KINDS = 'gameserver|kvm|proxmox|teamspeak3|teamspeak6|webspace|domain';

    public function __construct(private EntityManagerInterface $em, private SiteResolver $sites, private AuditLoggerInterface $audit, private CsrfTokenManagerInterface $csrf, private Environment $twig, private RentalCapacity $capacity, private \App\Module\Core\Application\Billing\PaymentSettings $payments, private \App\Module\Gameserver\Application\GameserverInstallDirectory $directories)
    {
    }

    #[Route('/admin/shop/rental-products', name: 'admin_rental_products', methods: ['GET', 'POST'])]
    #[Route('/admin/shop/products', name: 'admin_shop_products', methods: ['GET'])]
    #[Route('/admin/shop/rental-products/{kind}', name: 'admin_rental_product_kind', requirements: ['kind' => self::KINDS], methods: ['GET'])]
    public function index(Request $request, ?string $kind = null): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            return new Response('Site not found.', 404);
        }
        // Old bookmarked forms keep working through the same validated editor.
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('rental-products', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            $selected = ShopProductKind::tryFrom($request->request->getString('kind'));
            if (null === $selected) {
                return new Response('Unknown product kind.', 422);
            }

            return $this->editor($request, $selected->value, $request->request->getInt('product_id'));
        }
        $selected = null !== $kind ? ShopProductKind::from($kind) : null;
        $page = max(1, min(4000, $request->query->getInt('page', 1)));
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $counts = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT kind, COUNT(*) AS total FROM shop_products WHERE site_id = ? GROUP BY kind', [$site->getId()]) as $row) {
            $counts[(string) $row['kind']] = (int) $row['total'];
        }
        $products = [];
        $more = false;
        if (null !== $selected) {
            $query = $this->em->getRepository(ShopProduct::class)->createQueryBuilder('p')->addSelect('c')->join('p.category', 'c')
                ->where('p.siteId = :site AND p.kind = :kind')->setParameter('site', $site->getId())->setParameter('kind', $selected->value, \Doctrine\DBAL\Types\Types::STRING)
                ->orderBy('p.name', 'ASC')->addOrderBy('p.id', 'ASC')->setMaxResults(25)->setFirstResult(($page - 1) * 24);
            if ('' !== $search) {
                $query->andWhere('LOWER(p.name) LIKE :search')->setParameter('search', '%'.mb_strtolower($search).'%');
            }
            /** @var list<ShopProduct> $products */
            $products = $query->getQuery()->getResult();
            $more = count($products) > 24;
            $products = array_slice($products, 0, 24);
        }

        return new Response($this->twig->render('admin/shop/rental-products.html.twig', ['activeNav' => 'shop-products', 'kinds' => ShopProductKind::cases(), 'selected' => $selected, 'products' => $products, 'counts' => $counts, 'page' => $page, 'search' => $search, 'has_more' => $more, 'currency' => $this->payments->publicSettings()['currency']]));
    }

    #[Route('/admin/shop/rental-products/{kind}/new', name: 'admin_rental_product_new', requirements: ['kind' => self::KINDS], methods: ['GET', 'POST'])]
    #[Route('/admin/shop/rental-products/{kind}/{id}/edit', name: 'admin_rental_product_edit', requirements: ['kind' => self::KINDS, 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function editor(Request $request, string $kind, int $id = 0): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $site = $this->sites->resolve($request);
        if (null === $site) {
            return new Response('Site not found.', 404);
        }
        $kind = ShopProductKind::from($kind);
        $product = $id > 0 ? $this->em->find(ShopProduct::class, $id) : null;
        if ($id > 0 && (null === $product || $product->getSiteId() !== $site->getId() || $product->getKind() !== $kind)) {
            return new Response('Product not found.', 404);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('rental-products', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                if ($request->request->getString('kind') !== $kind->value || $request->request->getInt('product_id') !== ($product?->getId() ?? 0)) {
                    throw new \InvalidArgumentException('Produktart und Produktzuordnung dürfen nicht verändert werden.');
                }
                $category = $this->em->find(ShopCategory::class, $request->request->getInt('category_id'));
                $name = trim($request->request->getString('name'));
                $description = trim($request->request->getString('description'));
                if (null === $category || $category->getSiteId() !== $site->getId() || '' === $name || mb_strlen($name) > 160 || mb_strlen($description) > 10000) {
                    throw new \InvalidArgumentException('Produktname, Produktart und Kategorie prüfen.');
                }
                $price = BillingAmount::parse($request->request->getString('price'));
                if ($price > 10_000_000) {
                    throw new \InvalidArgumentException('Der Tarif überschreitet das unterstützte Maximum.');
                }
                $node = $this->em->find(Agent::class, $request->request->getString('node_id'));
                $template = ShopProductKind::Gameserver === $kind ? $this->em->find(Template::class, $request->request->getInt('template_id')) : null;
                if (in_array($kind, [ShopProductKind::Gameserver, ShopProductKind::Kvm, ShopProductKind::Webspace], true) && null === $node) {
                    throw new \InvalidArgumentException('Ein vorhandener Agent ist erforderlich.');
                }
                if (ShopProductKind::Gameserver === $kind && null === $template) {
                    throw new \InvalidArgumentException('Ein vorhandenes Gameserver-Template ist erforderlich.');
                }
                $cpu = $request->request->getInt('cpu', 1);
                $ram = $request->request->getInt('ram', 1);
                $disk = $request->request->getInt('disk', 10);
                if ($cpu < 1 || $cpu > 21_474_836 || $ram < 1 || $ram > 2_097_151 || $disk < 1 || $disk > 2_097_151 || ($kind->isVirtualMachine() && ($cpu > 256 || $ram > 1024 || $disk > 2048))) {
                    throw new \InvalidArgumentException('Ressourcen außerhalb der unterstützten Grenzen.');
                }
                $imageUrl = trim($request->request->getString('image_url'));
                if (strlen($imageUrl) > 255 || ('' !== $imageUrl && (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME)), ['http', 'https'], true)))) {
                    throw new \InvalidArgumentException('Für das Produktbild eine gültige HTTP(S)-Adresse mit höchstens 255 Zeichen angeben.');
                }
                $options = [];
                if (ShopProductKind::Gameserver === $kind) {
                    $slots = $request->request->getInt('slots', $product?->getGameserverSlots() ?? \App\Module\Core\Domain\Entity\Instance::DEFAULT_SLOTS);
                    if ($slots < 1 || $slots > 1024) {
                        throw new \InvalidArgumentException('Die Slotzahl muss zwischen 1 und 1024 liegen.');
                    }
                    $options['game_slots'] = $slots;
                    $root = trim($request->request->getString('instance_base_dir'));
                    if ('' !== $root) {
                        $root = $this->directories->resolve($node, $root);
                    }
                    $options['instance_base_dir'] = $root;
                }
                if (in_array($kind, [ShopProductKind::Teamspeak3, ShopProductKind::Teamspeak6], true)) {
                    $voice = ShopProductKind::Teamspeak3 === $kind ? $this->em->find(Ts3Node::class, $request->request->getInt('ts3_node_id')) : $this->em->find(Ts6Node::class, $request->request->getInt('ts6_node_id'));
                    $slots = $request->request->getInt('slots', 32);
                    if (null === $voice || $slots < 1 || $slots > 1024) {
                        throw new \InvalidArgumentException('Voice-Node und Slotzahl prüfen.');
                    }
                    $node = $voice->getAgent();
                    $options = ['voice_node_id' => $voice->getId() ?? 0, 'slots' => $slots];
                } elseif (ShopProductKind::Kvm === $kind) {
                    $image = trim($request->request->getString('kvm_template'));
                    $network = trim($request->request->getString('network', 'default'));
                    $networkType = $request->request->getString('network_type', 'network');
                    $addressMode = $request->request->getString('address_mode', 'dhcp');
                    if (!preg_match('/^[a-z][a-z0-9-]{0,47}--[a-z0-9][a-z0-9.-]{0,47}$/D', $image) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $network) || !in_array($networkType, ['network', 'bridge'], true)) {
                        throw new \InvalidArgumentException('KVM-Katalog-Template und Netzwerk prüfen.');
                    }
                    if (!in_array($addressMode, ['dhcp', 'pool'], true)) {
                        throw new \InvalidArgumentException('Ungültige KVM-Adressvergabe.');
                    }
                    $options = ['kvm_template' => $image, 'network' => $network, 'network_type' => $networkType, 'address_mode' => $addressMode];
                } elseif (ShopProductKind::Proxmox === $kind) {
                    $clusterId = $request->request->getInt('proxmox_cluster_id');
                    $host = trim($request->request->getString('proxmox_node'));
                    $vmId = $request->request->getInt('proxmox_template_id');
                    $addressMode = $request->request->getString('address_mode', 'dhcp');
                    if (null === $this->em->find(ProxmoxCluster::class, $clusterId) || $vmId < 100 || $vmId > 999999999
                        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/D', $host) || !in_array($addressMode, ['dhcp', 'pool'], true)) {
                        throw new \InvalidArgumentException('Proxmox-Cluster, Node, Vorlagen-ID und Adressvergabe prüfen.');
                    }
                    $node = null;
                    $options = ['proxmox_cluster_id' => $clusterId, 'proxmox_node' => $host, 'proxmox_template_id' => $vmId, 'address_mode' => $addressMode];
                } elseif (ShopProductKind::Webspace === $kind) {
                    $php = $request->request->getString('php_version', '8.4');
                    if (!preg_match('/^8\.[0-9]$/D', $php)) {
                        throw new \InvalidArgumentException('PHP-Version des Hosting-Nodes prüfen.');
                    } $options = ['php_version' => $php];
                } elseif (ShopProductKind::Domain === $kind) {
                    $term = $request->request->getInt('term_months', 12);
                    if (!in_array($term, [12, 24, 36], true)) {
                        throw new \InvalidArgumentException('Domainlaufzeit ungültig.');
                    } $registrar = $request->request->getString('registrar', 'manual');
                    $tld = strtolower(trim($request->request->getString('tld')));
                    if (!in_array($registrar, ['manual', 'autodns'], true) || ('autodns' === $registrar && !preg_match('/^[a-z0-9-]{2,63}(?:\.[a-z0-9-]{2,63})?$/D', $tld))) {
                        throw new \InvalidArgumentException('Registrar und Domainendung prüfen.');
                    }
                    $options = ['term_months' => $term, 'registrar' => $registrar, 'tld' => $tld];
                }
                if (null === $product) {
                    $product = new ShopProduct($site->getId() ?? 0, $category, $name, $description, $price, ShopProductKind::Gameserver === $kind ? $template : null, $node, $cpu, $ram, $disk);
                    $this->em->persist($product);
                } else {
                    $product->setName($name);
                    $product->setDescription($description);
                    $product->setCategory($category);
                    $product->setPriceMonthlyCents($price);
                    $product->setCpuLimit($cpu);
                    $product->setRamLimit($ram);
                    $product->setDiskLimit($disk);
                    if (null !== $node) {
                        $product->setNode($node);
                    } if (null !== $template && ShopProductKind::Gameserver === $kind) {
                        $product->setTemplate($template);
                    }
                }
                $product->setImageUrl('' === $imageUrl ? null : $imageUrl);
                $product->configureKind($kind, array_replace($product->getProvisioningOptions(), $options));
                $product->setIsActive($request->request->getBoolean('active'));
                $product->setIsPublicActive($request->request->getBoolean('public'));
                $product->setIsCustomerActive($request->request->getBoolean('customer'));
                $this->em->flush();
                $this->audit->log($actor, 'rental.product.saved', ['product_id' => $product->getId(), 'kind' => $kind->value]);

                return new RedirectResponse('/admin/shop/rental-products/'.$kind->value.'/'.($product->getId() ?? 0).'/edit?saved=1', 303);
            } catch (\DomainException|\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }
        $installDirectory = null;
        $directoryError = null;
        $provisioningNode = $product?->getProvisioningNode();
        if (ShopProductKind::Gameserver === $kind && null !== $product && null !== $provisioningNode) {
            try {
                $installDirectory = $this->directories->resolve($provisioningNode, (string) ($product->getProvisioningOptions()['instance_base_dir'] ?? ''));
            } catch (\DomainException $exception) {
                $directoryError = $exception->getMessage();
            }
        }
        $agentKind = in_array($kind, [ShopProductKind::Gameserver, ShopProductKind::Kvm, ShopProductKind::Webspace], true);

        return new Response($this->twig->render('admin/shop/product-editor.html.twig', ['activeNav' => 'shop-products', 'selected' => $kind, 'product' => $product, 'install_directory' => $installDirectory, 'directory_error' => $directoryError, 'input' => $request->isMethod('POST') ? JsonMap::strings($request->request->all()) : null,
            'categories' => $this->em->getRepository(ShopCategory::class)->findBy(['siteId' => $site->getId()], ['sortOrder' => 'ASC']),
            'nodes' => $agentKind ? $this->em->getRepository(Agent::class)->findAll() : [],
            'proxmox_clusters' => ShopProductKind::Proxmox === $kind ? $this->em->getRepository(ProxmoxCluster::class)->findBy([], ['name' => 'ASC']) : [],
            'templates' => ShopProductKind::Gameserver === $kind ? $this->em->getRepository(Template::class)->findAll() : [],
            'voice_nodes' => in_array($kind, [ShopProductKind::Teamspeak3, ShopProductKind::Teamspeak6], true) ? $this->em->getRepository(ShopProductKind::Teamspeak3 === $kind ? Ts3Node::class : Ts6Node::class)->findAll() : [], 'error' => $error]), null !== $error ? 422 : 200);
    }

    #[Route('/admin/shop/rental-products/{kind}/{id}/capacity', name: 'admin_rental_product_capacity', requirements: ['kind' => self::KINDS, 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function capacity(Request $request, string $kind, int $id): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $site = $this->sites->resolve($request);
        $product = $this->em->find(ShopProduct::class, $id);
        if (null === $site || null === $product || $product->getSiteId() !== $site->getId() || $product->getKind()->value !== $kind) {
            return new Response('Product not found.', 404);
        }
        $availability = $this->capacity->inspect($product);
        $target = $availability['target'];
        if (null === $target) {
            return new Response('Domains do not use capacity pools.', 404);
        }
        $pool = $this->em->find(RentalCapacityPool::class, $target);
        $error = null;
        $resources = array_keys(array_filter($availability['required'], static fn (int $amount): bool => $amount > 0));
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('product-capacity-'.$id, $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                $this->em->wrapInTransaction(function () use ($target, $resources, $request): void {
                    $pool = $this->em->find(RentalCapacityPool::class, $target, LockMode::PESSIMISTIC_WRITE) ?? new RentalCapacityPool($target, []);
                    $limits = $pool->getLimits();
                    foreach ($resources as $resource) {
                        $limits[$resource] = $request->request->getInt($resource);
                    }
                    $pool->configure($limits, $request->request->getBoolean('enabled'));
                    $this->em->persist($pool);
                    $this->em->flush();
                    $this->capacity->adoptExisting($pool);
                });
                $this->audit->log($actor, 'rental.capacity.changed', ['pool' => $target]);

                return new RedirectResponse('/admin/shop/rental-products/'.$kind.'/'.$id.'/capacity?saved=1', 303);
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return new Response($this->twig->render('admin/shop/product-capacity.html.twig', ['activeNav' => 'shop-products', 'product' => $product, 'availability' => $availability, 'pool' => $pool, 'resources' => $resources, 'error' => $error, 'input' => $request->isMethod('POST') ? JsonMap::strings($request->request->all()) : null]), null !== $error ? 422 : 200);
    }
}
