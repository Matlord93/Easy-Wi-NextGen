<?php

declare(strict_types=1);

use Psalm\Context;
use Psalm\Plugin\EventHandler\AfterClassLikeAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeAnalysisEvent;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/** Models the reflective reads performed by Doctrine's attribute mapping driver. */
final class EasyWiDoctrineMappingPlugin implements PluginEntryPointInterface, AfterClassLikeAnalysisInterface
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

        // Mapped classes are discovered from metadata and may be extended by
        // Doctrine proxies. Ordinary application classes retain unused/final checks.
        $storage->public_api = true;
        $context = new Context();
        $context->calling_method_id = 'doctrine\\orm\\unitofwork::computechangesets';
        foreach ($storage->properties as $name => $property) {
            foreach ($property->attributes as $attribute) {
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
