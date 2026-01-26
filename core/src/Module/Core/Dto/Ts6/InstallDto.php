<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Ts6;

final class InstallDto
{
    public function __construct(
        public string $downloadUrl,
        public string $installPath,
        public string $instanceName,
        public string $serviceName,
        public bool $acceptLicense = true,
        /** @var string[] */
        public array $voiceIp = ['0.0.0.0', '::'],
        public int $defaultVoicePort = 9987,
        public int $filetransferPort = 30033,
        /** @var string[] */
        public array $filetransferIp = ['0.0.0.0', '::'],
        public bool $queryHttpsEnable = true,
        public string $queryBindIp = '0.0.0.0',
        public int $queryHttpsPort = 10443,
        public ?string $adminPassword = null,
    ) {
    }
}
