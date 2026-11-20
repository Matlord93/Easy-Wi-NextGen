<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Application\InstanceSlotService;
use App\Repository\InstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/instances')]
final class AdminInstanceSlotsController
{
    public function __construct(
        private readonly InstanceRepositoryInterface $instanceRepository,
        private readonly InstanceSlotService $instanceSlotService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly ?\Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf = null,
    ) {
    }

    #[Route(path: '/{id}/slots', name: 'admin_instances_slots', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            return new Response('Instance not found.', Response::HTTP_NOT_FOUND);
        }

        $notice = null;
        $error = null;

        if ($request->isMethod('POST')) {
            if ($this->csrf === null || !$this->csrf->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('admin_instance_slots_'.$id, $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
            }
            $maxSlotsValue = $request->request->get('max_slots');
            $currentSlotsValue = $request->request->get('current_slots');
            $lockSlots = $request->request->get('lock_slots') === '1';

            if (!is_numeric($maxSlotsValue) || (int) $maxSlotsValue <= 0) {
                $error = 'max_slots must be a positive number.';
            } else {
                $instance->setMaxSlots((int) $maxSlotsValue);
                $instance->setLockSlots($lockSlots);

                if (is_numeric($currentSlotsValue)) {
                    $this->instanceSlotService->enforceSlots($instance, (int) $currentSlotsValue);
                } else {
                    $this->instanceSlotService->enforceSlots($instance, null);
                }

                $this->entityManager->persist($instance);
                $this->entityManager->flush();
                $notice = 'Slots updated.';
            }
        }

        return new Response($this->twig->render('admin/instances/slots.html.twig', [
            'instance' => $instance,
            'notice' => $notice,
            'error' => $error,
            'activeNav' => 'game-instances',
        ]));
    }
}
