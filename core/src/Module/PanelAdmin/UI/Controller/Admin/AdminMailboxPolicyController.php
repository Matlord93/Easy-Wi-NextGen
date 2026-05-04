<?php
declare(strict_types=1);
namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Job;
use App\Repository\MailboxRepository;
use App\Repository\MailDomainRepository;
use App\Repository\MailPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path:'/api/v1/admin/mail/mailboxes')]
final class AdminMailboxPolicyController
{
    public function __construct(private readonly MailboxRepository $mailboxRepository, private readonly MailPolicyRepository $mailPolicyRepository, private readonly MailDomainRepository $mailDomainRepository, private readonly AuditLogger $auditLogger, private readonly EntityManagerInterface $entityManager) {}

    #[Route(path:'/{id}/policy', methods:['POST'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $actor=$request->attributes->get('current_user'); if(!$actor instanceof User || !$actor->isAdmin()) return new JsonResponse(['error'=>'Forbidden'],403);
        if($request->headers->get('X-Admin-Request') !== '1') return new JsonResponse(['error'=>'Invalid request origin.'],403);
        $mailbox=$this->mailboxRepository->find($id); if($mailbox===null) return new JsonResponse(['error'=>'Mailbox not found'],404);
        try { $p=$request->toArray(); } catch (\Throwable) { return new JsonResponse(['error'=>'Invalid JSON payload'],400); }
        $send=(int)($p['send_limit_hour'] ?? 0); $rec=(int)($p['recipient_limit'] ?? 0);
        if($send<0||$rec<0) return new JsonResponse(['error'=>'Limits must be >= 0'],400);
        $smtpEnabled = filter_var($p['smtp_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $abuseEnabled = filter_var($p['abuse_policy_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $domain=$mailbox->getDomain();
        $mailDomain = $this->mailDomainRepository->findOneByDomain($domain);
        $agentId = $mailDomain?->getNode()?->getId() ?? $domain->getWebspace()?->getNode()?->getId();
        if ($agentId === null) return new JsonResponse(['error' => 'No mail agent/node assigned to this domain.'], 400);
        $policy=$this->mailPolicyRepository->findOneByDomain($domain) ?? new \App\Module\Core\Domain\Entity\MailPolicy($domain);
        $this->entityManager->persist($policy);
        $policy->apply($policy->isRequireTls(), $smtpEnabled, $rec, $send, $abuseEnabled, $policy->isAllowExternalForwarding(), $policy->getSpamProtectionLevel(), $policy->isGreylistingEnabled());
        $job = new Job('mailbox.policy.update', [
            'mailbox_id' => (string) $mailbox->getId(),
            'address' => $mailbox->getAddress(),
            'domain' => $domain->getName(),
            'local_part' => $mailbox->getLocalPart(),
            'mail_node_id' => (string) ($mailDomain?->getNode()?->getId() ?? ''),
            'node_id' => (string) $agentId,
            'agent_id' => (string) $agentId,
            'smtp_enabled' => $smtpEnabled ? 'true' : 'false',
            'send_limit_hour' => (string) $send,
            'recipient_limit' => (string) $rec,
            'abuse_policy_enabled' => $abuseEnabled ? 'true' : 'false',
            'mail_enabled' => 'true',
            'mail_backend' => 'local',
            'source' => 'admin_mail_policy',
        ]);
        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'admin.mail.policy_updated', ['mailbox_id'=>$mailbox->getId(),'domain_id'=>$domain->getId(),'smtp_enabled'=>$smtpEnabled,'send_limit_hour'=>$send,'recipient_limit'=>$rec,'abuse_policy_enabled'=>$abuseEnabled]);
        $this->entityManager->flush();
        return new JsonResponse(['ok'=>true,'mailbox_id'=>$id,'policy'=>['smtp_enabled'=>$smtpEnabled,'send_limit_hour'=>$send,'recipient_limit'=>$rec,'abuse_policy_enabled'=>$abuseEnabled],'job_id'=>$job->getId()]);
    }
}
