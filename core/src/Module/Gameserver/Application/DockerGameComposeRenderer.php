<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use Symfony\Component\Yaml\Yaml;

final readonly class DockerGameComposeRenderer
{
    public function __construct(private DockerGameTemplateCatalog $catalog)
    {
    }

    /** @param array<string, int> $hostPorts @param array<string, string> $environment */
    public function render(string $templateKey, string $project, array $hostPorts, array $environment = [], bool $sharedStorage = false): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $project)) {
            throw new \InvalidArgumentException('Invalid Docker game project name.');
        }

        $template = $this->catalog->get($templateKey);
        $ports = [];
        foreach ($template->ports as $name => $containerPort) {
            $hostPort = $hostPorts[$name] ?? null;
            if (!is_int($hostPort) || $hostPort < 1024 || $hostPort > 65535) {
                throw new \InvalidArgumentException(sprintf('Missing or invalid host port "%s".', $name));
            }
            $protocol = str_ends_with($name, '_tcp') ? 'tcp' : 'udp';
            if ('game' === $name) {
                $protocol = 'tcp';
            }
            $ports[] = sprintf('%d:%d/%s', $hostPort, $containerPort, $protocol);
        }

        $safeEnvironment = $template->environment;
        foreach ($environment as $key => $value) {
            if (1 !== preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key) || strlen($value) > 4096 || str_contains($value, "\0")) {
                throw new \InvalidArgumentException(sprintf('Invalid environment variable "%s".', $key));
            }
            $safeEnvironment[$key] = $value;
        }

        $volume = $sharedStorage ? 'easywi-games-shared:'.$template->dataPath : $project.'-data:'.$template->dataPath;
        $document = [
            'services' => [
                'gameserver' => [
                    'image' => $template->image,
                    'container_name' => $project,
                    'restart' => 'unless-stopped',
                    'environment' => $safeEnvironment,
                    'ports' => $ports,
                    'volumes' => [$volume],
                    'labels' => ['easywi.managed' => 'true', 'easywi.game' => $template->key, 'easywi.project' => $project],
                    'security_opt' => ['no-new-privileges:true'],
                ],
            ],
            'volumes' => $sharedStorage
                ? ['easywi-games-shared' => ['external' => true]]
                : [$project.'-data' => []],
        ];

        return Yaml::dump($document, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
