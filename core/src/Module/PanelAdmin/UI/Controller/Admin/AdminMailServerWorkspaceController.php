<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\MailDomain;
use App\Module\Core\Domain\Entity\MailNode;
use App\Module\Core\Domain\Entity\User;
use App\Repository\AgentRepositoryInterface;
use App\Repository\DomainRepositoryInterface;
use App\Repository\MailDomainRepositoryInterface;
use App\Repository\MailNodeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[RequiresModule('mail')]
final class AdminMailServerWorkspaceController
{
    public function __construct(
        private readonly MailNodeRepositoryInterface $nodes,
        private readonly MailDomainRepositoryInterface $bindings,
        private readonly DomainRepositoryInterface $domains,
        private readonly AgentRepositoryInterface $agents,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $audit,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/admin/mail/servers', name: 'admin_mail_servers', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('admin_mail_servers', $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            try {
                $action = $request->request->getString('action');
                $node = $this->nodes->find($request->request->getInt('node_id'));
                if ('assign' === $action) {
                    $domain = $this->domains->find($request->request->getInt('domain_id'));
                    if (null === $node || null === $domain || null === $node->getAgent() && null === $domain->getWebspace()) {
                        throw new \InvalidArgumentException('Select a domain and a mail server with a provisioning agent.');
                    }
                    $binding = $this->bindings->findOneByDomain($domain);
                    if (null !== $binding && $binding->getNode()->getId() !== $node->getId()) {
                        throw new \InvalidArgumentException('Moving an existing mail domain requires a mailbox migration first.');
                    }
                    if (null === $binding) {
                        $this->em->persist(new MailDomain($domain, $node));
                    }
                    $domain->setCapabilities($domain->hasWebspaceCapability(), true);
                    $this->audit->log($actor, 'mail.domain.assigned', ['domain_id' => $domain->getId(), 'mail_node_id' => $node->getId()]);
                } elseif (in_array($action, ['create', 'edit'], true)) {
                    if ('edit' === $action && null === $node) {
                        throw new \InvalidArgumentException('Mail server not found.');
                    }
                    $name = trim($request->request->getString('name'));
                    $imap = trim($request->request->getString('imap_host'));
                    $smtp = trim($request->request->getString('smtp_host'));
                    $imapPort = $request->request->getInt('imap_port');
                    $smtpPort = $request->request->getInt('smtp_port');
                    $url = trim($request->request->getString('roundcube_url'));
                    $agentId = $request->request->getString('agent_id');
                    $agent = '' === $agentId ? null : $this->agents->find($agentId);
                    if ('' === $name || mb_strlen($name) > 120 || !$this->host($imap) || !$this->host($smtp) || $imapPort < 1 || $imapPort > 65535 || $smtpPort < 1 || $smtpPort > 65535 || null === $agent) {
                        throw new \InvalidArgumentException('Enter a name, valid IMAP/SMTP hosts and ports, and select a provisioning agent.');
                    }
                    if ('' !== $url && (strlen($url) > 255 || false === filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true))) {
                        throw new \InvalidArgumentException('The webmail URL must use https:// or http://.');
                    }
                    $previousAgent = $node?->getAgent();
                    if (null !== $node && null !== $previousAgent && $previousAgent->getId() !== $agent->getId() && $this->bindings->count(['node' => $node]) > 0) {
                        throw new \InvalidArgumentException('A bound mail server cannot change provisioning agents without migrating its mailboxes.');
                    }
                    if ('create' === $action) {
                        $node = new MailNode($name, $imap, $imapPort, $smtp, $smtpPort, '' === $url ? null : $url);
                    }
                    if (null === $node) {
                        throw new \LogicException('Missing mail server.');
                    }
                    $node->updateConnection($name, $imap, $imapPort, $smtp, $smtpPort, '' === $url ? null : $url);
                    $node->setAgent($agent);
                    $this->em->persist($node);
                    $this->audit->log($actor, 'mail.server.'.$action, ['name' => $name, 'agent_id' => $agent->getId()]);
                } else {
                    throw new \InvalidArgumentException('Unsupported action.');
                }
                $this->em->flush();

                return new RedirectResponse('/admin/mail/servers?saved=1');
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return new Response($this->twig->render('admin/mail-system/servers.html.twig', ['activeNav' => 'mail-servers', 'servers' => $this->nodes->findBy([], ['name' => 'ASC']), 'agents' => $this->agents->findBy([], ['name' => 'ASC']), 'domains' => $this->domains->findBy([], ['name' => 'ASC']), 'error' => $error, 'saved' => $request->query->getBoolean('saved')]), null === $error ? 200 : 422);
    }

    private function host(string $host): bool
    {
        return '' !== $host && strlen($host) <= 255 && (false !== filter_var($host, FILTER_VALIDATE_IP) || false !== filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME));
    }
}
