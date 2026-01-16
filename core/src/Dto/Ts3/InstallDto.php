<?php

declare(strict_types=1);

namespace App\Dto\Ts3;

final class InstallDto
{
    public function __construct(
        public string $downloadUrl,
        public string $installPath,
        public string $instanceName,
        public string $serviceName,
        public bool $acceptLicense = true,
        public string $queryBindIp = '127.0.0.1',
        public int $queryPort = 10011,
        public ?string $adminPassword = null,
    ) {
    }
}
