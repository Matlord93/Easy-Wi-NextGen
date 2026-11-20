<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\InstanceBaseDirectory;
use App\Module\Core\Domain\Entity\Agent;

final readonly class GameserverInstallDirectory
{
    public function __construct(private AppSettingsServiceInterface $settings)
    {
    }

    /** @return list<string> */
    public function configured(Agent $node): array
    {
        $metadata = $node->getMetadata() ?? [];
        $raw = $metadata['instance_base_dirs'] ?? [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Die Installationsverzeichnisse des Ziel-Nodes sind ungültig. Bitte die Node-Einstellungen prüfen.');
        }
        $paths = [];
        foreach ($raw as $path) {
            if (!is_string($path) || '' === trim($path)) {
                continue;
            }
            $paths[] = InstanceBaseDirectory::normalize($path);
        }

        return array_values(array_unique($paths));
    }

    public function resolve(Agent $node, ?string $selected = null): string
    {
        try {
            $configured = $this->configured($node);
            if (null !== $selected && '' !== trim($selected)) {
                $selected = InstanceBaseDirectory::normalize($selected);
                if ([] !== $configured && !in_array($selected, $configured, true)) {
                    throw new \InvalidArgumentException('Das gewählte Installationsverzeichnis ist für diesen Node nicht hinterlegt.');
                }

                return $selected;
            }
            if ([] !== $configured) {
                return $configured[0];
            }

            return InstanceBaseDirectory::normalize($this->settings->getInstanceBaseDir());
        } catch (\InvalidArgumentException $exception) {
            throw new \DomainException('Der Installationspfad des Zielsystems ist nicht korrekt eingerichtet. Bitte die Installationsverzeichnisse des Nodes prüfen.', 0, $exception);
        }
    }
}
