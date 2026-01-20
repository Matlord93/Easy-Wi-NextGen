<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts3;

final class Ts3NodeDto
{
    public const DEFAULT_DOWNLOAD_URL = 'https://files.teamspeak-services.com/releases/server/3.13.7/teamspeak3-server_linux_amd64-3.13.7.tar.bz2';
    public const DEFAULT_INSTALL_PATH = '/home/teamspeak3';
    public const DEFAULT_INSTANCE_NAME = 'teamspeak3';
    public const DEFAULT_SERVICE_NAME = 'teamspeak3';

    public function __construct(
        public string $name = '',
        public string $agentNodeId = '',
        public string $agentBaseUrl = '',
        public string $agentApiToken = '',
        public string $downloadUrl = self::DEFAULT_DOWNLOAD_URL,
        public string $installPath = self::DEFAULT_INSTALL_PATH,
        public string $instanceName = self::DEFAULT_INSTANCE_NAME,
        public string $serviceName = self::DEFAULT_SERVICE_NAME,
        public string $queryBindIp = '127.0.0.1',
        public int $queryPort = 10011,
        public int $filetransferPort = 30033,
    ) {
    }
}
