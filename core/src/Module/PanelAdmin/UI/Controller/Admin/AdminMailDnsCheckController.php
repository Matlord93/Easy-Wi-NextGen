<?php
declare(strict_types=1);
namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\MailDnsCheckService;
use App\Module\Core\Domain\Entity\User;
use App\Repository\MailDomainRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/mail/domains')]
final class AdminMailDnsCheckController
{
    public function __construct(private readonly MailDomainRepository $mailDomainRepository, private readonly MailDnsCheckService $mailDnsCheckService) {}

    #[Route(path: '/{id}/dns-check', methods: ['GET'])]
    public function check(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) return new JsonResponse(['error'=>'Forbidden'],403);
        $mailDomain = $this->mailDomainRepository->find($id);
        if ($mailDomain === null) return new JsonResponse(['error'=>'Mail domain not found'],404);
        return new JsonResponse($this->mailDnsCheckService->check($mailDomain->getDomain()->getName(), $mailDomain->getNode()->getImapHost()));
    }
}
