<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\EngineType;
use App\Repository\AgentRepositoryInterface;
use App\Repository\DatabaseNodeRepositoryInterface;
use App\Repository\DatabaseRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AdminDatabaseNodeEditorController
{
    public function __construct(
        private readonly DatabaseNodeRepositoryInterface $nodes,
        private readonly DatabaseRepositoryInterface $databases,
        private readonly AgentRepositoryInterface $agents,
        private readonly EncryptionServiceInterface $encryption,
        private readonly AuditLoggerInterface $audit,
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/admin/databases/nodes/{id}/edit', name: 'admin_database_nodes_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }
        $node = $this->nodes->find($id);
        if (null === $node) {
            throw new NotFoundHttpException();
        }
        $boundCount = $this->databases->count(['node' => $node]);
        $form = ['name' => $node->getName(), 'engine' => $node->getEngine(), 'host' => $node->getHost(), 'port' => (string) $node->getPort(), 'agent_id' => $node->getAgent()->getId(), 'tls_mode' => $node->getTlsMode(), 'ca_cert' => $node->getCaCert() ?? '', 'admin_user' => $node->getAdminUser() ?? '', 'tags' => implode(', ', array_filter($node->getTags(), 'is_string'))];
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('admin_database_node_edit_'.$id, $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            foreach (array_keys($form) as $key) {
                $form[$key] = trim($request->request->getString($key));
            }
            $port = filter_var($form['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            $agent = $this->agents->find($form['agent_id']);
            $targetChanged = $form['host'] !== $node->getHost() || $port !== $node->getPort() || $form['engine'] !== $node->getEngine() || $form['agent_id'] !== $node->getAgent()->getId();
            if ('' === $form['name'] || mb_strlen($form['name']) > 120) {
                $errors[] = 'db_node_invalid_name';
            }
            if (!in_array($form['engine'], EngineType::values(), true)) {
                $errors[] = 'db_node_invalid_engine';
            }
            if ('' === $form['host'] || strlen($form['host']) > 255 || false === filter_var($form['host'], FILTER_VALIDATE_IP) && false === filter_var($form['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                $errors[] = 'db_node_invalid_host';
            }
            if (false === $port) {
                $errors[] = 'db_node_invalid_port';
            }
            if (null === $agent || !in_array('DB', $agent->getRoles(), true) && $agent->getId() !== $node->getAgent()->getId()) {
                $errors[] = 'db_node_invalid_agent';
            }
            if (!in_array($form['tls_mode'], ['off', 'required', 'verify_ca', 'verify_full'], true)) {
                $errors[] = 'db_node_invalid_tls';
            }
            if (mb_strlen($form['admin_user']) > 190 || strlen($form['ca_cert']) > 65535 || strlen($form['tags']) > 2048) {
                $errors[] = 'db_node_invalid_fields';
            }
            if ($boundCount > 0 && $targetChanged) {
                $errors[] = 'db_node_target_bound';
            }
            $secret = $request->request->getString('admin_secret');
            $encryptedSecret = $node->getEncryptedAdminSecret();
            if ([] === $errors && '' !== $secret) {
                try {
                    $encryptedSecret = $this->encryption->encrypt($secret);
                } catch (\RuntimeException) {
                    $errors[] = 'db_node_secret_failed';
                }
            }
            if ([] === $errors && null !== $agent && false !== $port) {
                $connectionChanged = $targetChanged || '' !== $secret || $form['tls_mode'] !== $node->getTlsMode() || $form['ca_cert'] !== ($node->getCaCert() ?? '') || $form['admin_user'] !== ($node->getAdminUser() ?? '');
                $node->setName($form['name']);
                $node->setEngine($form['engine']);
                $node->setHost($form['host']);
                $node->setPort($port);
                $node->setAgent($agent);
                $node->setTlsMode($form['tls_mode']);
                $node->setCaCert('' === $form['ca_cert'] ? null : $form['ca_cert']);
                $node->setAdminUser('' === $form['admin_user'] ? null : $form['admin_user']);
                $node->setTags(explode(',', $form['tags']));
                $node->setEncryptedAdminSecret($encryptedSecret);
                if ($connectionChanged) {
                    $node->resetHealth();
                }
                $this->audit->log($actor, 'database.node.updated', ['database_node_id' => $id, 'agent_id' => $agent->getId(), 'target_changed' => $targetChanged, 'password_changed' => '' !== $secret, 'tls_mode' => $form['tls_mode']]);
                $this->em->flush();

                return new RedirectResponse('/admin/databases/nodes/'.$id.'/edit?saved=1');
            }
        }
        if ($boundCount > 0) {
            $form['host'] = $node->getHost();
            $form['port'] = (string) $node->getPort();
            $form['engine'] = $node->getEngine();
            $form['agent_id'] = $node->getAgent()->getId();
        }
        $agents = array_filter($this->agents->findBy([], ['name' => 'ASC']), static fn ($agent): bool => in_array('DB', $agent->getRoles(), true) || $agent->getId() === $node->getAgent()->getId());

        return new Response($this->twig->render('admin/databases/node_edit.html.twig', ['activeNav' => 'databases', 'node' => $node, 'form' => $form, 'agents' => $agents, 'engines' => EngineType::values(), 'boundCount' => $boundCount, 'errors' => array_map(fn (string $key): string => $this->translator->trans($key, [], 'panel_modules'), $errors), 'saved' => $request->query->getBoolean('saved')]), [] === $errors ? 200 : 422);
    }
}
