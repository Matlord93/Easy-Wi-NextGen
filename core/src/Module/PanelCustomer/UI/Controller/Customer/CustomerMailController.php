<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\MailboxRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/mail')]
final class CustomerMailController
{
    public function __construct(
        private readonly MailboxRepository $mailboxRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_mail', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $mailboxes = $this->mailboxRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/mail/index.html.twig', [
            'activeNav' => 'mail',
            'mailboxes' => $this->normalizeMailboxes($mailboxes),
        ]));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @param Mailbox[] $mailboxes
     */
    private function normalizeMailboxes(array $mailboxes): array
    {
        return array_map(static function (Mailbox $mailbox): array {
            return [
                'id' => $mailbox->getId(),
                'address' => $mailbox->getAddress(),
                'domain' => $mailbox->getDomain()->getName(),
                'quota' => $mailbox->getQuota(),
                'enabled' => $mailbox->isEnabled(),
                'updated_at' => $mailbox->getUpdatedAt(),
            ];
        }, $mailboxes);
    }
}
