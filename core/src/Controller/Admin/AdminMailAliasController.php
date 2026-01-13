<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Job;
use App\Entity\MailAlias;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\MailAliasRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/mail-aliases')]
final class AdminMailAliasController
{
    private string $aliasMapPath;

    public function __construct(
        private readonly MailAliasRepository $aliasRepository,
        private readonly DomainRepository $domainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
        #[Autowire('%env(default::APP_MAIL_ALIAS_MAP_PATH)%')]
        ?string $aliasMapPath,
    ) {
        $this->aliasMapPath = $aliasMapPath ?? '';
    }

    #[Route(path: '', name: 'admin_mail_aliases', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $aliases = $this->aliasRepository->findBy([], ['updatedAt' => 'DESC']);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);
        $summary = $this->buildSummary($aliases);

        return new Response($this->twig->render('admin/mail-aliases/index.html.twig', [
            'aliases' => $this->normalizeAliases($aliases),
            'domains' => $domains,
            'summary' => $summary,
            'form' => $this->buildFormContext(),
            'activeNav' => 'mail-aliases',
        ]));
    }

    #[Route(path: '/table', name: 'admin_mail_aliases_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $aliases = $this->aliasRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/mail-aliases/_table.html.twig', [
            'aliases' => $this->normalizeAliases($aliases),
        ]));
    }

    #[Route(path: '/form', name: 'admin_mail_aliases_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/mail-aliases/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_mail_aliases_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $alias = $this->aliasRepository->find($id);
        if ($alias === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/mail-aliases/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext($alias),
        ]));
    }

    #[Route(path: '', name: 'admin_mail_aliases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request, true);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($domains, $formData, Response::HTTP_BAD_REQUEST);
        }

        $alias = new MailAlias(
            $formData['domain'],
            $formData['local_part'],
            $formData['destinations'],
            $formData['enabled'],
        );

        $this->entityManager->persist($alias);
        $this->entityManager->flush();

        $job = $this->queueAliasJob('mail.alias.create', $alias, [
            'destinations' => implode(', ', $alias->getDestinations()),
            'enabled' => $alias->isEnabled() ? 'true' : 'false',
        ]);

        $this->auditLogger->log($actor, 'mail.alias_created', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/mail-aliases/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'mail-aliases-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_mail_aliases_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $alias = $this->aliasRepository->find($id);
        if ($alias === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, false, $alias);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($domains, $formData, Response::HTTP_BAD_REQUEST, $alias);
        }

        $previous = [
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
        ];

        $alias->setDestinations($formData['destinations']);
        $alias->setEnabled($formData['enabled']);

        $job = $this->queueAliasJob('mail.alias.update', $alias, [
            'destinations' => implode(', ', $alias->getDestinations()),
            'enabled' => $alias->isEnabled() ? 'true' : 'false',
        ]);

        $this->auditLogger->log($actor, 'mail.alias_updated', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'previous' => $previous,
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/mail-aliases/_form.html.twig', [
            'domains' => $domains,
            'form' => $this->buildFormContext($alias),
        ]));
        $response->headers->set('HX-Trigger', 'mail-aliases-changed');

        return $response;
    }

    #[Route(path: '/{id}/enable', name: 'admin_mail_aliases_enable', methods: ['POST'])]
    public function enable(Request $request, int $id): Response
    {
        return $this->setAliasStatus($request, $id, true);
    }

    #[Route(path: '/{id}/disable', name: 'admin_mail_aliases_disable', methods: ['POST'])]
    public function disable(Request $request, int $id): Response
    {
        return $this->setAliasStatus($request, $id, false);
    }

    #[Route(path: '/{id}/delete', name: 'admin_mail_aliases_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $alias = $this->aliasRepository->find($id);
        if ($alias === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $job = $this->queueAliasJob('mail.alias.delete', $alias, []);

        $this->auditLogger->log($actor, 'mail.alias_deleted', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->remove($alias);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'mail-aliases-changed');

        return $response;
    }

    private function setAliasStatus(Request $request, int $id, bool $enabled): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $alias = $this->aliasRepository->find($id);
        if ($alias === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        if ($alias->isEnabled() !== $enabled) {
            $previousEnabled = $alias->isEnabled();
            $alias->setEnabled($enabled);

            $jobType = $enabled ? 'mail.alias.enable' : 'mail.alias.disable';
            $job = $this->queueAliasJob($jobType, $alias, [
                'destinations' => implode(', ', $alias->getDestinations()),
                'enabled' => $alias->isEnabled() ? 'true' : 'false',
            ]);

            $this->auditLogger->log($actor, $enabled ? 'mail.alias_enabled' : 'mail.alias_disabled', [
                'alias_id' => $alias->getId(),
                'address' => $alias->getAddress(),
                'destinations' => $alias->getDestinations(),
                'enabled' => $alias->isEnabled(),
                'previous_enabled' => $previousEnabled,
                'job_id' => $job->getId(),
            ]);

            $this->entityManager->flush();
        }

        $aliases = $this->aliasRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/mail-aliases/_table.html.twig', [
            'aliases' => $this->normalizeAliases($aliases),
        ]));
    }

    private function parsePayload(Request $request, bool $requireIdentity, ?MailAlias $alias = null): array
    {
        $domainId = $request->request->get('domain_id');
        $localPart = strtolower(trim((string) $request->request->get('local_part', '')));
        $destinationsInput = trim((string) $request->request->get('destinations', ''));
        $enabled = $request->request->getBoolean('enabled', true);
        $errors = [];

        $domain = null;
        if (is_numeric($domainId)) {
            $domain = $this->domainRepository->find((int) $domainId);
        }

        if ($requireIdentity) {
            if ($domain === null) {
                $errors[] = 'Domain is required.';
            }

            if ($localPart === '') {
                $errors[] = 'Alias name is required.';
            } elseif (!preg_match('/^[a-z0-9._+\\-]+$/i', $localPart)) {
                $errors[] = 'Alias name contains invalid characters.';
            } elseif (str_contains($localPart, '@')) {
                $errors[] = 'Alias name must not include @.';
            }
        } elseif ($alias !== null) {
            $domain = $alias->getDomain();
            $localPart = $alias->getLocalPart();
        }

        $destinations = $this->parseDestinations($destinationsInput);
        if ($destinations === []) {
            $errors[] = 'Forward destinations are required.';
        }

        if ($domain !== null && $localPart !== '' && $requireIdentity) {
            $address = sprintf('%s@%s', $localPart, $domain->getName());
            $existing = $this->aliasRepository->findOneByAddress($address);
            if ($existing !== null) {
                $errors[] = 'Alias address already exists.';
            }
        }

        return [
            'domain' => $domain,
            'local_part' => $localPart,
            'destinations' => $destinations,
            'destinations_input' => $destinationsInput,
            'enabled' => $enabled,
            'errors' => $errors,
        ];
    }

    private function buildFormContext(?MailAlias $alias = null, ?array $override = null): array
    {
        $data = [
            'id' => $alias?->getId(),
            'domain_id' => $alias?->getDomain()->getId(),
            'domain_name' => $alias?->getDomain()->getName(),
            'address' => $alias?->getAddress(),
            'local_part' => $alias?->getLocalPart() ?? '',
            'destinations' => $alias ? implode(', ', $alias->getDestinations()) : '',
            'enabled' => $alias?->isEnabled() ?? true,
            'errors' => [],
            'action' => $alias === null ? 'create' : 'update',
            'submit_label' => $alias === null ? 'Create Alias' : 'Update Alias',
            'submit_color' => $alias === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $alias === null ? '/admin/mail-aliases' : sprintf('/admin/mail-aliases/%d', $alias->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $domains, array $formData, int $status, ?MailAlias $alias = null): Response
    {
        $formContext = $this->buildFormContext($alias, [
            'domain_id' => $formData['domain']?->getId(),
            'domain_name' => $formData['domain']?->getName(),
            'local_part' => $formData['local_part'],
            'destinations' => $formData['destinations_input'],
            'enabled' => $formData['enabled'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/mail-aliases/_form.html.twig', [
            'domains' => $domains,
            'form' => $formContext,
        ]), $status);
    }

    private function queueAliasJob(string $type, MailAlias $alias, array $extraPayload): Job
    {
        $domain = $alias->getDomain();
        $payload = array_merge([
            'alias_id' => (string) ($alias->getId() ?? ''),
            'domain_id' => (string) $domain->getId(),
            'local_part' => $alias->getLocalPart(),
            'address' => $alias->getAddress(),
            'map_path' => $this->aliasMapPath !== '' ? $this->aliasMapPath : '/etc/postfix/virtual_aliases',
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    /**
     * @param MailAlias[] $aliases
     */
    private function buildSummary(array $aliases): array
    {
        $summary = [
            'total' => count($aliases),
            'enabled' => 0,
            'disabled' => 0,
            'destinations' => 0,
        ];

        foreach ($aliases as $alias) {
            if ($alias->isEnabled()) {
                $summary['enabled']++;
            } else {
                $summary['disabled']++;
            }
            $summary['destinations'] += count($alias->getDestinations());
        }

        return $summary;
    }

    /**
     * @param MailAlias[] $aliases
     */
    private function normalizeAliases(array $aliases): array
    {
        return array_map(function (MailAlias $alias): array {
            return [
                'id' => $alias->getId(),
                'address' => $alias->getAddress(),
                'domain' => [
                    'id' => $alias->getDomain()->getId(),
                    'name' => $alias->getDomain()->getName(),
                ],
                'customer' => [
                    'id' => $alias->getCustomer()->getId(),
                    'email' => $alias->getCustomer()->getEmail(),
                ],
                'destinations' => $alias->getDestinations(),
                'enabled' => $alias->isEnabled(),
                'updatedAt' => $alias->getUpdatedAt(),
            ];
        }, $aliases);
    }

    /**
     * @return string[]
     */
    private function parseDestinations(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $normalized = str_replace(["\r\n", "\n", ";"], ',', $input);
        $parts = array_map('trim', explode(',', $normalized));
        $filtered = array_filter($parts, static fn (string $value): bool => $value !== '');

        return array_values(array_unique($filtered));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
