<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts6;

final class Ts6NodeDto
{
    public const DEFAULT_DOWNLOAD_URL = 'https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0%2Fbeta8/teamspeak-server_linux_amd64-v6.0.0-beta8.tar.bz2';
    public const DEFAULT_INSTALL_PATH = '/home/teamspeak6';
    public const DEFAULT_INSTANCE_NAME = 'teamspeak6';
    public const DEFAULT_SERVICE_NAME = 'teamspeak6';

    public function __construct(
        public string $name = '',
        public string $agentNodeId = '',
        public string $agentBaseUrl = '',
        public string $agentApiToken = '',
        public ?string $osType = null,
        public string $downloadUrl = self::DEFAULT_DOWNLOAD_URL,
        public string $installPath = self::DEFAULT_INSTALL_PATH,
        public string $instanceName = self::DEFAULT_INSTANCE_NAME,
        public string $serviceName = self::DEFAULT_SERVICE_NAME,
        public string $queryBindIp = '0.0.0.0',
        public int $queryHttpsPort = 10443,
        public int $voicePort = 9987,
    ) {
    }
}
