<?php

declare(strict_types=1);

use Psalm\Context;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\Storage\ClassLikeStorage;

/** Models the reflective reads performed by Doctrine's attribute mapping driver. */
final class EasyWiDoctrineMappingPlugin implements PluginEntryPointInterface, AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface, AfterClassLikeAnalysisInterface
{
    private const MAPPED_CLASSES = [
        'Doctrine\\ORM\\Mapping\\Entity',
        'Doctrine\\ORM\\Mapping\\MappedSuperclass',
        'Doctrine\\ORM\\Mapping\\Embeddable',
    ];

    private const MAPPED_FIELDS = [
        'Doctrine\\ORM\\Mapping\\Column',
        'Doctrine\\ORM\\Mapping\\ManyToOne',
        'Doctrine\\ORM\\Mapping\\OneToOne',
        'Doctrine\\ORM\\Mapping\\OneToMany',
        'Doctrine\\ORM\\Mapping\\ManyToMany',
        'Doctrine\\ORM\\Mapping\\Embedded',
    ];

    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        $registration->registerHooksFromClass(self::class);
    }

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        // This metadata must be set before Psalm caches and distributes class
        // storage to analysis workers. An analysis-only mutation can disappear
        // on subsequent cached scans or when the parent checks unused classes.
        self::markMappedApi($event->getStorage());
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        // Also reconcile storage loaded from a previous cache before workers
        // are forked. Unreferenced mapped classes may never reach analysis.
        foreach ($event->getCodebase()->classlike_storage_provider->getAll() as $storage) {
            self::markMappedApi($storage);
        }
    }

    private static function markMappedApi(ClassLikeStorage $storage): void
    {
        foreach ($storage->attributes as $attribute) {
            if (in_array($attribute->fq_class_name, self::MAPPED_CLASSES, true)) {
                $storage->public_api = true;

                return;
            }
        }
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterClassLikeAnalysisEvent $event): ?bool
    {
        $storage = $event->getClasslikeStorage();
        $mapped = false;
        foreach ($storage->attributes as $attribute) {
            if (in_array($attribute->fq_class_name, self::MAPPED_CLASSES, true)) {
                $mapped = true;
                break;
            }
        }
        if (!$mapped) {
            return null;
        }

        $context = new Context();
        $context->calling_method_id = 'doctrine\\orm\\unitofwork::computechangesets';
        // Psalm stores attributes of promoted properties on constructor
        // parameters. Doctrine reflects the corresponding property attributes.
        $promoted = [];
        $node = $event->getStmt();
        if ($node instanceof \PhpParser\Node\Stmt\Class_) {
            foreach ($node->getMethod('__construct')?->params ?? [] as $parameter) {
                if ($parameter->flags !== 0 && is_string($parameter->var->name)) {
                    $promoted[$parameter->var->name] = true;
                }
            }
        }
        $promotedAttributes = [];
        foreach ($storage->methods['__construct']->params ?? [] as $parameter) {
            if (isset($promoted[$parameter->name])) {
                $promotedAttributes[$parameter->name] = $parameter->attributes;
            }
        }
        foreach ($storage->properties as $name => $property) {
            $attributes = array_merge($property->attributes, $promotedAttributes[$name] ?? []);
            foreach ($attributes as $attribute) {
                if (in_array($attribute->fq_class_name, self::MAPPED_FIELDS, true)) {
                    $event->getCodebase()->addReferenceToProperty(
                        strtolower($storage->name),
                        $name,
                        true,
                        $property->location,
                        $context,
                    );
                    break;
                }
            }
        }

        return null;
    }
}

return new EasyWiDoctrineMappingPlugin();
