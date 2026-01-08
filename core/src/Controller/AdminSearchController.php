<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DomainRepository;
use App\Repository\InstanceRepository;
use App\Repository\TicketRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin')]
final class AdminSearchController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly InstanceRepository $instanceRepository,
        private readonly DomainRepository $domainRepository,
        private readonly TicketRepository $ticketRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/search', name: 'admin_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $query = trim((string) $request->query->get('q', ''));
        $results = $query === '' ? [] : $this->performSearch($query);

        $template = $request->headers->get('HX-Request') ? 'admin/search/_results.html.twig' : 'admin/search/index.html.twig';

        return new Response($this->twig->render($template, [
            'activeNav' => 'search',
            'query' => $query,
            'results' => $results,
        ]));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function performSearch(string $query): array
    {
        $like = '%' . addcslashes($query, '%_') . '%';

        $customers = $this->userRepository->createQueryBuilder('user')
            ->andWhere('user.type = :type')
            ->andWhere('user.email LIKE :query')
            ->setParameter('type', UserType::Customer->value)
            ->setParameter('query', $like)
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $instances = $this->instanceRepository->createQueryBuilder('instance')
            ->leftJoin('instance.template', 'template')
            ->leftJoin('instance.customer', 'customer')
            ->andWhere('template.name LIKE :query OR customer.email LIKE :query')
            ->setParameter('query', $like)
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $domains = $this->domainRepository->createQueryBuilder('domain')
            ->leftJoin('domain.customer', 'customer')
            ->andWhere('domain.name LIKE :query OR customer.email LIKE :query')
            ->setParameter('query', $like)
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        $tickets = $this->ticketRepository->createQueryBuilder('ticket')
            ->leftJoin('ticket.customer', 'customer')
            ->andWhere('ticket.subject LIKE :query OR customer.email LIKE :query')
            ->setParameter('query', $like)
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return [
            'customers' => $this->normalizeCustomers($customers),
            'instances' => $this->normalizeInstances($instances),
            'domains' => $this->normalizeDomains($domains),
            'tickets' => $this->normalizeTickets($tickets),
        ];
    }

    private function normalizeCustomers(array $customers): array
    {
        return array_map(static function (User $customer): array {
            return [
                'id' => $customer->getId(),
                'email' => $customer->getEmail(),
                'type' => $customer->getType()->value,
            ];
        }, $customers);
    }

    private function normalizeInstances(array $instances): array
    {
        return array_map(static function (\App\Entity\Instance $instance): array {
            return [
                'id' => $instance->getId(),
                'customer' => $instance->getCustomer()->getEmail(),
                'template' => $instance->getTemplate()->getName(),
                'status' => $instance->getStatus()->value,
            ];
        }, $instances);
    }

    private function normalizeDomains(array $domains): array
    {
        return array_map(static function (\App\Entity\Domain $domain): array {
            return [
                'id' => $domain->getId(),
                'name' => $domain->getName(),
                'customer' => $domain->getCustomer()->getEmail(),
                'status' => $domain->getStatus(),
            ];
        }, $domains);
    }

    private function normalizeTickets(array $tickets): array
    {
        return array_map(static function (\App\Entity\Ticket $ticket): array {
            return [
                'id' => $ticket->getId(),
                'subject' => $ticket->getSubject(),
                'customer' => $ticket->getCustomer()->getEmail(),
                'status' => $ticket->getStatus()->value,
            ];
        }, $tickets);
    }
}
