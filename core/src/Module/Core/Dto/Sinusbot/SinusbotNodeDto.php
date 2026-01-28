<?php

declare(strict_types=1);

namespace App\Module\Core\Dto\Sinusbot;

final class SinusbotNodeDto
{
    public string $name = '';
    public ?int $customerId = null;
    public string $agentNodeId = '';
    public string $agentBaseUrl = '';
    public string $agentApiToken = '';
    public string $downloadUrl = 'https://michael.frie.se/sinusbot-1.1f-amd64.tar.bz2';
    public string $installPath = '/opt/sinusbot';
    public string $instanceRoot = '/opt/sinusbot/instances';
    public string $webBindIp = '0.0.0.0';
    public int $webPortBase = 8087;
}
