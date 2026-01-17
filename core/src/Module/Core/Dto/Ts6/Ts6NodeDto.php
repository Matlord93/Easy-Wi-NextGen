<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts6;

final class Ts6NodeDto
{
    public function __construct(
        public string $name = '',
        public string $agentNodeId = '',
        public string $agentBaseUrl = '',
        public string $agentApiToken = '',
        public ?string $osType = null,
        public string $downloadUrl = '',
        public string $installPath = '',
        public string $instanceName = '',
        public string $serviceName = '',
        public string $queryBindIp = '127.0.0.1',
        public int $queryHttpsPort = 10443,
    ) {
    }
}
