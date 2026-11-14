<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class NodeSftpHostKeyController
{
    public function __construct(private AgentRepository $nodes, private EntityManagerInterface $em, private CsrfTokenManagerInterface $csrf, private AuditLogger $audit)
    {
    }

    #[Route('/admin/nodes/{id}/sftp-host-key', name: 'admin_nodes_sftp_host_key', methods: ['POST'])]
    public function update(Request $request, string $id): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin() || !$this->csrf->isTokenValid(new CsrfToken('node-sftp-host-key-'.$id, (string) $request->request->get('_token', '')))) {
            return new Response('Forbidden', 403);
        }
        $node = $this->nodes->find($id);
        if (null === $node) {
            return new Response('Node not found.', 404);
        }
        if (!$request->request->has('fingerprint')) {
            return new Response('Fingerprint required.', 422);
        }
        $previous = $node->getSftpHostFingerprint();
        try {
            $node->setSftpHostFingerprint((string) $request->request->get('fingerprint'));
        } catch (\InvalidArgumentException) {
            return new Response('An OpenSSH SHA256 host fingerprint is required.', 422);
        }
        $this->audit->log($actor, 'node.sftp_host_key_updated', ['node_id' => $id, 'previous_fingerprint' => $previous, 'fingerprint' => $node->getSftpHostFingerprint()]);
        $this->em->flush();

        return new RedirectResponse('/admin/nodes?node='.rawurlencode($id).'#section-sftp-host-key', 303);
    }
}
