<?php

declare(strict_types=1);

namespace App\Module\Ports\UI\Controller\Admin;

use App\Entity\User;
use App\Form\PortRangeFormType;
use App\Repository\AgentRepository;
use App\Service\AuditLogger;
use App\Module\Ports\Domain\Entity\PortRange;
use App\Module\Ports\Infrastructure\Repository\PortRangeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/nodes/{id}/port-ranges')]
final class AdminNodePortRangeController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly PortRangeRepository $portRangeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly PortRangeFormType $formType,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_node_port_ranges', methods: ['GET'])]
    public function index(Request $request, string $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->renderPage($node);
    }

    #[Route(path: '', name: 'admin_node_port_ranges_create', methods: ['POST'])]
    public function create(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->formType->getDataFromRequest($request);
        $errors = $this->formType->validate($formData);
        if ($errors !== []) {
            return $this->renderPage($node, $this->mapFormError($errors[0]), null, null, $formData);
        }

        $range = new PortRange(
            $node,
            $formData['purpose'],
            $formData['protocol'],
            $formData['start_port'] ?? 0,
            $formData['end_port'] ?? 0,
            $formData['enabled'],
        );

        $this->entityManager->persist($range);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'port_range.created', [
            'port_range_id' => $range->getId(),
            'node_id' => $node->getId(),
            'purpose' => $range->getPurpose(),
            'protocol' => $range->getProtocol(),
            'start_port' => $range->getStartPort(),
            'end_port' => $range->getEndPort(),
            'enabled' => $range->isEnabled(),
        ]);

        $overlaps = $this->portRangeRepository->findOverlaps(
            $node,
            $range->getProtocol(),
            $range->getStartPort(),
            $range->getEndPort(),
            $range->getId(),
        );

        $warning = $overlaps !== []
            ? ['key' => 'admin_port_ranges_overlap_warning', 'count' => count($overlaps)]
            : null;

        return $this->renderPage($node, null, 'admin_port_ranges_notice_created', $warning);
    }

    #[Route(path: '/{rangeId}/update', name: 'admin_node_port_ranges_update', methods: ['POST'])]
    public function update(Request $request, string $id, int $rangeId): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $range = $this->portRangeRepository->find($rangeId);
        if ($range === null || $range->getNode()->getId() !== $node->getId()) {
            return $this->renderPage($node, 'admin_port_ranges_error_not_found');
        }

        $formData = $this->formType->getDataFromRequest($request);
        $errors = $this->formType->validate($formData);
        if ($errors !== []) {
            return $this->renderPage($node, $this->mapFormError($errors[0]));
        }

        $range->setPurpose($formData['purpose']);
        $range->setProtocol($formData['protocol']);
        $range->setRange($formData['start_port'] ?? 0, $formData['end_port'] ?? 0);
        $range->setEnabled($formData['enabled']);

        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'port_range.updated', [
            'port_range_id' => $range->getId(),
            'node_id' => $node->getId(),
            'purpose' => $range->getPurpose(),
            'protocol' => $range->getProtocol(),
            'start_port' => $range->getStartPort(),
            'end_port' => $range->getEndPort(),
            'enabled' => $range->isEnabled(),
        ]);

        $overlaps = $this->portRangeRepository->findOverlaps(
            $node,
            $range->getProtocol(),
            $range->getStartPort(),
            $range->getEndPort(),
            $range->getId(),
        );

        $warning = $overlaps !== []
            ? ['key' => 'admin_port_ranges_overlap_warning', 'count' => count($overlaps)]
            : null;

        return $this->renderPage($node, null, 'admin_port_ranges_notice_updated', $warning);
    }

    #[Route(path: '/{rangeId}/delete', name: 'admin_node_port_ranges_delete', methods: ['POST'])]
    public function delete(Request $request, string $id, int $rangeId): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $node = $this->agentRepository->find($id);
        if ($node === null) {
            return new Response('Node not found.', Response::HTTP_NOT_FOUND);
        }

        $range = $this->portRangeRepository->find($rangeId);
        if ($range === null || $range->getNode()->getId() !== $node->getId()) {
            return $this->renderPage($node, 'admin_port_ranges_error_not_found');
        }

        $this->entityManager->remove($range);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'port_range.deleted', [
            'port_range_id' => $rangeId,
            'node_id' => $node->getId(),
        ]);

        return $this->renderPage($node, null, 'admin_port_ranges_notice_deleted');
    }

    private function renderPage(
        \App\Entity\Agent $node,
        ?string $errorKey = null,
        ?string $noticeKey = null,
        ?array $warning = null,
        array $form = [],
    ): Response {
        $ranges = $this->portRangeRepository->findByNode($node);

        return new Response($this->twig->render('admin/nodes/port-ranges.html.twig', [
            'node' => [
                'id' => $node->getId(),
                'name' => $node->getName(),
            ],
            'ranges' => array_map(fn (PortRange $range) => $this->normalizeRange($range), $ranges),
            'form' => array_merge([
                'purpose' => '',
                'protocol' => 'tcp',
                'start_port' => '',
                'end_port' => '',
                'enabled' => true,
            ], $form),
            'protocols' => $this->formType->getProtocols(),
            'errorKey' => $errorKey,
            'noticeKey' => $noticeKey,
            'warning' => $warning,
            'activeNav' => 'nodes',
        ]));
    }

    private function normalizeRange(PortRange $range): array
    {
        return [
            'id' => $range->getId(),
            'purpose' => $range->getPurpose(),
            'protocol' => $range->getProtocol(),
            'start_port' => $range->getStartPort(),
            'end_port' => $range->getEndPort(),
            'enabled' => $range->isEnabled(),
            'created_at' => $range->getCreatedAt(),
            'updated_at' => $range->getUpdatedAt(),
        ];
    }

    private function mapFormError(string $errorKey): string
    {
        return match ($errorKey) {
            'purpose_required' => 'admin_port_ranges_error_purpose_required',
            'purpose_too_long' => 'admin_port_ranges_error_purpose_length',
            'protocol_invalid' => 'admin_port_ranges_error_protocol',
            'ports_required' => 'admin_port_ranges_error_ports_required',
            'ports_out_of_range' => 'admin_port_ranges_error_ports_range',
            'ports_order_invalid' => 'admin_port_ranges_error_ports_order',
            default => 'admin_port_ranges_error_generic',
        };
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
