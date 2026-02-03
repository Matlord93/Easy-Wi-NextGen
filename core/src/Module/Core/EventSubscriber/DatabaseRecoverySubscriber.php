<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DatabaseRecoverySubscriber implements EventSubscriberInterface
{
    private const EXCLUDED_PREFIXES = [
        '/install',
        '/system/health',
        '/system/recovery',
    ];

    private const SQLSTATE_CODES = [
        '42S02', // table not found
        '42S22', // column not found
        '42S01', // table exists (migration failure)
    ];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $throwable = $event->getThrowable();
        if (!$this->isSchemaMismatch($throwable)) {
            return;
        }

        $target = $this->urlGenerator->generate('system_recovery_database');
        $event->setResponse(new RedirectResponse($target, 302));
    }

    private function isSchemaMismatch(\Throwable $throwable): bool
    {
        if ($throwable instanceof TableNotFoundException) {
            return true;
        }

        if ($throwable instanceof InvalidFieldNameException) {
            return true;
        }

        if ($throwable instanceof DriverException) {
            $sqlState = $throwable->getSQLState();
            if (is_string($sqlState) && in_array($sqlState, self::SQLSTATE_CODES, true)) {
                return true;
            }
        }

        if ($throwable instanceof DbalException) {
            $message = $throwable->getMessage();
            if ($this->messageIndicatesMismatch($message)) {
                return true;
            }
        }

        $previous = $throwable->getPrevious();
        return $previous instanceof \Throwable && $this->isSchemaMismatch($previous);
    }

    private function messageIndicatesMismatch(string $message): bool
    {
        $needles = [
            'SQLSTATE[42S02]',
            'SQLSTATE[42S22]',
            'Base table or view not found',
            'Unknown column',
            'Column not found',
            'Table doesn\'t exist',
        ];

        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
