<?php

declare(strict_types=1);

namespace App\Module\Voice\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\ModuleRegistry;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\User;
use App\Module\Voice\Application\TeamspeakServerOperationsInterface;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route('/admin/voice')]
final class AdminVoiceWorkspaceController
{
    public function __construct(
        private readonly Ts3VirtualServerRepository $ts3,
        private readonly Ts6VirtualServerRepository $ts6,
        private readonly UserRepositoryInterface $users,
        private readonly TeamspeakServerOperationsInterface $operations,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $audit,
        private readonly ModuleRegistry $modules,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'admin_voice_workspace', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->admin($request);
        $servers = [];
        foreach (['ts3' => $this->ts3, 'ts6' => $this->ts6] as $provider => $repository) {
            if (!$this->modules->isEnabled('ts3' === $provider ? 'ts' : 'ts6')) {
                continue;
            }
            foreach ($repository->findBy(['archivedAt' => null], ['name' => 'ASC']) as $server) {
                $servers[] = ['provider' => $provider, 'server' => $server];
            }
        }

        $nodeRows = [];
        $online = 0;
        $customerIds = [];
        foreach ($servers as $row) {
            $server = $row['server'];
            $customerIds[] = $server->getCustomerId();
            $nodeKey = $row['provider'].':'.($server->getNode()->getId() ?? 0);
            $nodeRows[$nodeKey] ??= ['name' => $server->getNode()->getName(), 'provider' => $row['provider'], 'count' => 0];
            ++$nodeRows[$nodeKey]['count'];
            if (in_array($server->getStatus(), ['online', 'running'], true)) {
                ++$online;
            }
        }
        $customerNames = [];
        foreach (([] === $customerIds ? [] : $this->users->findBy(['id' => array_values(array_unique($customerIds))])) as $customer) {
            $name = $customer->getName();
            $customerNames[$customer->getId() ?? 0] = null !== $name && '' !== $name ? $name : $customer->getEmail();
        }

        return new Response($this->twig->render('admin/voice/index.html.twig', ['activeNav' => 'voice-workspace', 'servers' => $servers, 'nodeRows' => $nodeRows, 'customerNames' => $customerNames, 'online' => $online]));
    }

    #[Route('/{provider}/{id}/edit', name: 'admin_voice_edit', requirements: ['provider' => 'ts3|ts6', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $provider, int $id): Response
    {
        $actor = $this->admin($request);
        if (!$this->modules->isEnabled('ts3' === $provider ? 'ts' : 'ts6')) {
            throw new NotFoundHttpException();
        }
        $server = 'ts3' === $provider ? $this->ts3->find($id) : $this->ts6->find($id);
        if (!$server instanceof Ts3VirtualServer && !$server instanceof Ts6VirtualServer || null !== $server->getArchivedAt()) {
            throw new NotFoundHttpException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('admin_voice_edit_'.$provider.'_'.$id, $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            try {
                $action = $request->request->getString('action', 'save');
                if ('save' === $action) {
                    $customer = $this->users->find($request->request->getInt('customer_id'));
                    $host = trim($request->request->getString('public_host'));
                    if (null === $customer || !$customer->isCustomer()) {
                        throw new \InvalidArgumentException('Customer not found.');
                    }
                    if ('' !== $host && false === filter_var($host, FILTER_VALIDATE_IP) && (strlen($host) > 253 || false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
                        throw new \InvalidArgumentException('Enter a hostname or IP address without protocol or port.');
                    }
                    if ($customer->getId() !== $server->getCustomerId() && ($server->getSid() <= 0 || in_array($server->getStatus(), ['pending', 'creating', 'deleting', 'failed'], true))) {
                        throw new \InvalidArgumentException('Assignment is unavailable while the server is being provisioned or removed.');
                    }
                    $previousCustomer = $server->getCustomerId();
                    $server->setCustomerId($customer->getId() ?? 0);
                    $server->setPublicHost('' === $host ? null : $host);
                    $this->audit->log($actor, 'voice.admin.settings', ['provider' => $provider, 'server_id' => $id, 'previous_customer_id' => $previousCustomer, 'customer_id' => $server->getCustomerId(), 'public_host' => $host]);
                } else {
                    if (!in_array($action, ['start', 'stop', 'restart'], true) || !$request->request->getBoolean('confirm_action')) {
                        throw new \InvalidArgumentException('Confirm the server action.');
                    }
                    $job = $this->operations->action($server, $action);
                    $this->audit->log($actor, 'voice.admin.'.$action, ['provider' => $provider, 'server_id' => $id, 'job_id' => $job->getId()]);
                }
                $this->em->flush();

                return new RedirectResponse($this->urls->generate('admin_voice_edit', ['provider' => $provider, 'id' => $id, ('save' === $action ? 'saved' : 'queued') => 1]));
            } catch (\InvalidArgumentException|\RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        return new Response($this->twig->render('admin/voice/edit.html.twig', ['activeNav' => 'voice-workspace', 'server' => $server, 'provider' => $provider, 'customers' => $this->users->findCustomers(), 'error' => $error, 'saved' => $request->query->getBoolean('saved'), 'queued' => $request->query->getBoolean('queued')]), null === $error ? 200 : 422);
    }

    private function admin(Request $request): User
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }

        return $actor;
    }
}
