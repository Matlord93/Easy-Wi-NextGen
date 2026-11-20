<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Module\Core\Application\ModuleRegistry;
use App\Module\Core\Attribute\RequiresModule;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class ModuleAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ModuleRegistry $moduleRegistry)
    {
    }

    /** @return array{'kernel.controller': string} */
    #[\Override]
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

        if (str_starts_with($controllerObject::class, 'App\\Module\\Cms\\UI\\Controller\\Public\\')
            && !str_starts_with($event->getRequest()->attributes->getString('_route'), 'public_cookie_consent_')
            && !$this->moduleRegistry->isEnabled('cms')) {
            if ('public_cms_home' === $event->getRequest()->attributes->get('_route')) {
                $actor = \App\Infrastructure\Http\RequestActor::user($event->getRequest());
                $target = null === $actor ? '/login' : ($actor->isAdmin() ? '/admin' : (\App\Module\Core\Domain\Enum\UserType::Reseller === $actor->getType() ? '/reseller' : ($actor->isCustomerAccessEnabled() ? '/customer' : '/marketplace')));
                $event->setController(static fn (): RedirectResponse => new RedirectResponse($target));

                return;
            }
            throw new NotFoundHttpException('The integrated CMS is disabled.');
        }

        $attributes = (new \ReflectionClass($controllerObject))->getAttributes(RequiresModule::class);
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            if (!$this->moduleRegistry->isEnabled($instance->moduleKey)) {
                throw new NotFoundHttpException('This module is not enabled.');
            }
        }
    }
}
