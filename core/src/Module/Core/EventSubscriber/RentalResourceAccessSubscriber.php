<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\ShopContract;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Ended rentals remain readable, but existing mutation APIs cannot reactivate them. */
final readonly class RentalResourceAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'check'];
    }

    public function check(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $actor = RequestActor::user($request);
        if (!$event->isMainRequest() || $request->isMethodSafe() || null === $actor || !$actor->isCustomer()) {
            return;
        }
        $controller = $event->getController();
        $object = is_array($controller) ? $controller[0] : $controller;
        $class = is_object($object) ? $object::class : '';
        $gameController = str_starts_with($class, 'App\\Module\\Gameserver\\') || str_ends_with($class, '\\CustomerJobApiController');
        $webController = str_ends_with($class, '\\CustomerWebspaceController') || str_ends_with($class, '\\WebspaceApiController') || str_ends_with($class, '\\CustomerDomainController');
        if (!$gameController && !$webController) {
            return;
        }
        $path = $request->getPathInfo();
        $kind = null;
        $id = 0;
        if ($gameController && preg_match('~/(?:instances|gameservers)/(\d+)(?:/|$)~D', $path, $match)) {
            $kind = 'gameserver';
            $id = (int) $match[1];
        } elseif ($webController && preg_match('~/(?:webspace|webspaces)/(\d+)(?:/|$)~D', $path, $match)) {
            $kind = 'webspace';
            $id = (int) $match[1];
        } elseif (str_contains($path, '/jobs') || str_contains($path, '/domains')) {
            $input = JsonMap::strings($request->request->all());
            $json = JsonMap::decode($request->getContent()) ?? [];
            $instance = self::identifier($input['instance_id'] ?? $json['instance_id'] ?? null);
            $webspace = self::identifier($input['webspace_id'] ?? $json['webspace_id'] ?? null);
            if ($instance > 0) {
                $kind = 'gameserver';
                $id = $instance;
            } elseif ($webspace > 0) {
                $kind = 'webspace';
                $id = $webspace;
            } elseif (str_contains($path, '/domains') && preg_match('~/domains/(\d+)(?:/|$)~D', $path, $match)) {
                $domain = $this->em->find(Domain::class, (int) $match[1]);
                $kind = 'webspace';
                $id = $domain?->getWebspace()?->getId() ?? 0;
            }
        }
        if (null === $kind || $id < 1) {
            return;
        }
        $query = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->join('c.account', 'a')->join('c.order', 'o')
            ->where('a.customer = :customer AND c.kind = :kind AND c.endedAt IS NOT NULL')->setParameter('customer', $actor->getId(), \Doctrine\DBAL\Types\Types::INTEGER)->setParameter('kind', $kind);
        if ('gameserver' === $kind) {
            $query->andWhere('o.instance = :resource')->setParameter('resource', $id, \Doctrine\DBAL\Types\Types::INTEGER);
        } else {
            $query->andWhere('c.resourceId = :resource')->setParameter('resource', (string) $id);
        }
        if (null !== $query->setMaxResults(1)->getQuery()->getOneOrNullResult()) {
            $event->setController(static fn (): Response => new Response('Diese Ressource gehört zu einem beendeten Mietvertrag. Änderungen sind nicht mehr möglich.', 403));
        }
    }

    private static function identifier(mixed $value): int
    {
        return (is_int($value) || is_string($value)) && ctype_digit((string) $value) ? (int) $value : 0;
    }
}
