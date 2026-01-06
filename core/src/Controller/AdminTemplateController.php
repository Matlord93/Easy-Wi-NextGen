<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Template;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\TemplateRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/templates')]
final class AdminTemplateController
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_templates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/templates/index.html.twig', [
            'templates' => $this->normalizeTemplates($templates),
            'summary' => $this->buildSummary($templates),
            'form' => $this->buildFormContext(),
            'activeNav' => 'templates',
        ]));
    }

    #[Route(path: '/table', name: 'admin_templates_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/templates/_table.html.twig', [
            'templates' => $this->normalizeTemplates($templates),
        ]));
    }

    #[Route(path: '/form', name: 'admin_templates_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/templates/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '', name: 'admin_templates_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request);

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $template = new Template(
            $formData['name'],
            $formData['description'],
            $formData['start_params'],
            $formData['required_ports'],
            $formData['install_command'],
            $formData['update_command'],
            $formData['allowed_switch_flags'],
        );

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'template.created', [
            'template_id' => $template->getId(),
            'name' => $template->getName(),
            'required_ports' => $template->getRequiredPorts(),
            'start_params' => $template->getStartParams(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/templates/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'templates-changed');

        return $response;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function buildSummary(array $templates): array
    {
        $requiredPorts = 0;
        foreach ($templates as $template) {
            $requiredPorts += count($template->getRequiredPorts());
        }

        return [
            'total' => count($templates),
            'ports' => $requiredPorts,
            'commands' => count(array_filter($templates, fn (Template $template) => $template->getInstallCommand() !== '')),
        ];
    }

    private function normalizeTemplates(array $templates): array
    {
        return array_map(static function (Template $template): array {
            return [
                'id' => $template->getId(),
                'name' => $template->getName(),
                'description' => $template->getDescription(),
                'start_params' => $template->getStartParams(),
                'required_ports' => $template->getRequiredPorts(),
                'install_command' => $template->getInstallCommand(),
                'update_command' => $template->getUpdateCommand(),
                'allowed_switch_flags' => $template->getAllowedSwitchFlags(),
                'updated_at' => $template->getUpdatedAt(),
            ];
        }, $templates);
    }

    private function buildFormContext(?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'name' => '',
            'description' => '',
            'start_params' => '',
            'required_ports' => '',
            'install_command' => '',
            'update_command' => '',
            'allowed_switch_flags' => '',
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];
        $name = trim((string) $request->request->get('name', ''));
        $description = trim((string) $request->request->get('description', ''));
        $startParams = trim((string) $request->request->get('start_params', ''));
        $requiredPortsRaw = trim((string) $request->request->get('required_ports', ''));
        $installCommand = trim((string) $request->request->get('install_command', ''));
        $updateCommand = trim((string) $request->request->get('update_command', ''));
        $allowedSwitchFlagsRaw = trim((string) $request->request->get('allowed_switch_flags', ''));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($startParams === '') {
            $errors[] = 'Start params are required.';
        }
        if ($requiredPortsRaw === '') {
            $errors[] = 'Required ports are required.';
        }
        if ($installCommand === '') {
            $errors[] = 'Install command is required.';
        }
        if ($updateCommand === '') {
            $errors[] = 'Update command is required.';
        }

        $requiredPorts = $this->parsePortList($requiredPortsRaw, $errors, 'Required ports');
        $allowedSwitchFlags = $this->parseList($allowedSwitchFlagsRaw);

        return [
            'errors' => $errors,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'start_params' => $startParams,
            'required_ports' => $requiredPorts,
            'install_command' => $installCommand,
            'update_command' => $updateCommand,
            'allowed_switch_flags' => $allowedSwitchFlags,
            'required_ports_raw' => $requiredPortsRaw,
            'allowed_switch_flags_raw' => $allowedSwitchFlagsRaw,
        ];
    }

    private function parsePortList(string $value, array &$errors, string $label): array
    {
        if ($value === '') {
            return [];
        }

        $ports = [];
        foreach ($this->parseList($value) as $entry) {
            if (!is_numeric($entry)) {
                $errors[] = sprintf('%s must be numeric.', $label);
                continue;
            }
            $port = (int) $entry;
            if ($port <= 0 || $port > 65535) {
                $errors[] = sprintf('%s must be between 1 and 65535.', $label);
                continue;
            }
            $ports[] = $port;
        }

        if ($ports === []) {
            $errors[] = sprintf('%s cannot be empty.', $label);
        }

        return array_values(array_unique($ports));
    }

    private function parseList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $items = array_filter(array_map('trim', explode(',', $value)), static fn (string $item) => $item !== '');

        return array_values(array_unique($items));
    }

    private function renderFormWithErrors(array $formData, int $status): Response
    {
        return new Response($this->twig->render('admin/templates/_form.html.twig', [
            'form' => $this->buildFormContext([
                'errors' => $formData['errors'],
                'name' => $formData['name'],
                'description' => $formData['description'] ?? '',
                'start_params' => $formData['start_params'],
                'required_ports' => $formData['required_ports_raw'],
                'install_command' => $formData['install_command'],
                'update_command' => $formData['update_command'],
                'allowed_switch_flags' => $formData['allowed_switch_flags_raw'],
            ]),
        ]), $status);
    }
}
