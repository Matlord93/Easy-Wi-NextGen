<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Mail;

use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\MailUser;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

final class MailUserProjector implements EventSubscriber
{
    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $mailUserMetadata = $entityManager->getClassMetadata(MailUser::class);

        $mailboxes = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Mailbox) {
                $mailboxes[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Mailbox) {
                $mailboxes[spl_object_id($entity)] = $entity;
            }
        }

        if ($mailboxes === []) {
            return;
        }

        $mailUserRepository = $entityManager->getRepository(MailUser::class);

        foreach ($mailboxes as $mailbox) {
            $mailUser = $mailUserRepository->findOneBy(['mailbox' => $mailbox]);
            if (!$mailUser instanceof MailUser) {
                $mailUser = new MailUser($mailbox);
                $entityManager->persist($mailUser);
                $unitOfWork->computeChangeSet($mailUserMetadata, $mailUser);

                continue;
            }

            $mailUser->syncFromMailbox($mailbox);
            $unitOfWork->recomputeSingleEntityChangeSet($mailUserMetadata, $mailUser);
        }
    }
}
