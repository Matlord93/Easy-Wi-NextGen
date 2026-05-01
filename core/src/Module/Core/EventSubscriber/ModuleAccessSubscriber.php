<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Application\ModuleRegistry;
use App\Module\Core\Attribute\RequiresModule;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class ModuleAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ModuleRegistry $moduleRegistry)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();
        $controllerObject = is_array($controller) ? $controller[0] : $controller;
        if (!is_object($controllerObject)) {
            return;
        }

        $attributes = (new \ReflectionClass($controllerObject))->getAttributes(RequiresModule::class);
        foreach ($attributes as $attribute) {
            /** @var RequiresModule $instance */
            $instance = $attribute->newInstance();
            if (!$this->moduleRegistry->isEnabled($instance->moduleKey)) {
                throw new NotFoundHttpException('This module is not enabled.');
            }
        }
    }
}
