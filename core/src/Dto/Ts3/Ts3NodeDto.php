<?php

declare(strict_types=1);

namespace App\Dto\Ts3;

final class Ts3NodeDto
{
    public function __construct(
        public string $name = '',
        public string $agentBaseUrl = '',
        public string $agentApiToken = '',
        public string $downloadUrl = '',
        public string $installPath = '',
        public string $instanceName = '',
        public string $serviceName = '',
        public string $queryBindIp = '127.0.0.1',
        public int $queryPort = 10011,
    ) {
    }
}
