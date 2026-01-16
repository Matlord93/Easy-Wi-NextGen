<?php

declare(strict_types=1);

namespace App\Dto\Sinusbot;

final class SinusbotNodeDto
{
    public string $name = '';
    public string $agentBaseUrl = '';
    public string $agentApiToken = '';
    public string $downloadUrl = 'https://michael.frie.se/sinusbot-1.1f-amd64.tar.bz2';
    public string $installPath = '/opt/sinusbot';
    public string $instanceRoot = '/opt/sinusbot/instances';
    public string $webBindIp = '127.0.0.1';
    public int $webPortBase = 8087;
}
