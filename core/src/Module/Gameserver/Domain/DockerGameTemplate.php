<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Domain;

final readonly class DockerGameTemplate
{
    /** @param array<string, int> $ports @param array<string, string> $environment */
    public function __construct(
        public string $key,
        public string $name,
        public string $image,
        public array $ports,
        public array $environment,
        public string $dataPath,
        public string $configPath,
    ) {
    }
}
