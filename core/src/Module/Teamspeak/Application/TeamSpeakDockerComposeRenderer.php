<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application;

use Symfony\Component\Yaml\Yaml;

final class TeamSpeakDockerComposeRenderer
{
    /** @param array{voice_udp: int, query_tcp: int, file_tcp: int} $ports */
    public function render(string $project, array $ports, bool $licenseAccepted): string
    {
        if (!$licenseAccepted) {
            throw new \InvalidArgumentException('The TeamSpeak license must be accepted explicitly.');
        }
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $project)) {
            throw new \InvalidArgumentException('Invalid TeamSpeak Docker project name.');
        }
        foreach (['voice_udp', 'query_tcp', 'file_tcp'] as $name) {
            if (!isset($ports[$name]) || $ports[$name] < 1024 || $ports[$name] > 65535) {
                throw new \InvalidArgumentException(sprintf('Missing or invalid TeamSpeak port "%s".', $name));
            }
        }
        if (count(array_unique($ports)) !== 3) {
            throw new \InvalidArgumentException('TeamSpeak host ports must be unique.');
        }

        return Yaml::dump([
            'services' => ['teamspeak' => [
                'image' => 'teamspeak:latest',
                'container_name' => $project,
                'restart' => 'unless-stopped',
                'environment' => ['TS3SERVER_LICENSE' => 'accept'],
                'env_file' => ['.env'],
                'ports' => [
                    $ports['voice_udp'].':9987/udp',
                    $ports['query_tcp'].':10011/tcp',
                    $ports['file_tcp'].':30033/tcp',
                ],
                'volumes' => [$project.'-data:/var/ts3server'],
                'labels' => ['easywi.managed' => 'true', 'easywi.service' => 'teamspeak', 'easywi.project' => $project],
                'security_opt' => ['no-new-privileges:true'],
            ]],
            'volumes' => [$project.'-data' => []],
        ], 8, 2);
    }

    /** @param array<string, string> $secrets */
    public function renderSecretEnvironment(array $secrets): string
    {
        $allowed = ['TS3SERVER_SERVERADMIN_PASSWORD', 'TS3SERVER_QUERY_PROTOCOLS', 'TS3SERVER_DB_PLUGIN', 'TS3SERVER_DB_SQLCREATEPATH'];
        $lines = [];
        foreach ($secrets as $key => $value) {
            if (!in_array($key, $allowed, true) || str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new \InvalidArgumentException(sprintf('Invalid TeamSpeak environment secret "%s".', $key));
            }
            $lines[] = $key.'='.$value;
        }

        return implode("\n", $lines).($lines === [] ? '' : "\n");
    }
}
