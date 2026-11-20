<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\PublicServer;
use App\Repository\PublicServerRepository;

final readonly class CmsServerBlocks
{
    public function __construct(private PublicServerRepository $servers, private CmsFeatureToggle $features)
    {
    }

    /** @return array{type:string,html:string,servers:list<array<string,mixed>>,settings:array{show_players:bool,show_join_button:bool}} */
    public function renderData(CmsBlock $block): array
    {
        $settings = 2 === $block->getVersion() ? ($block->getPayloadJson() ?? []) : (JsonMap::decode($block->getContent()) ?? []);
        $game = trim(ScalarValue::text($settings['game'] ?? null));
        $limit = max(1, min(50, ScalarValue::integer($settings['limit'] ?? 6, 6)));
        if ('server_featured' === $block->getType()) {
            $limit = 1;
        }
        $site = $block->getPage()->getSite();
        $servers = $this->features->isEnabled($site, 'gameserver')
            ? $this->servers->findVisiblePublicBySite($site->getId() ?? 0, $game, null, $limit)
            : [];

        return [
            'type' => $block->getType(), 'html' => '',
            'servers' => array_map(self::normalize(...), array_values($servers)),
            'settings' => [
                'show_players' => filter_var($settings['show_players'] ?? true, FILTER_VALIDATE_BOOL),
                'show_join_button' => filter_var($settings['show_join_button'] ?? true, FILTER_VALIDATE_BOOL),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function normalize(PublicServer $server): array
    {
        $cache = $server->getStatusCache();
        $state = strtolower(ScalarValue::text($cache['status'] ?? $cache['online'] ?? null));
        $status = match ($state) {
            '1', 'online', 'running', 'up', 'alive', 'ok', 'success', 'reachable' => 'online',
            'offline', 'down', 'stopped', 'unreachable', 'timeout' => 'offline',
            'error' => 'error',
            default => ($cache['status'] ?? $cache['online'] ?? null) === false ? 'offline' : 'unknown',
        };

        return [
            'id' => $server->getId(), 'name' => $server->getName(), 'game_key' => $server->getGameKey(),
            'address' => $server->getIp().':'.$server->getPort(), 'status' => $status,
            'players' => is_numeric($cache['players'] ?? null) ? max(0, (int) $cache['players']) : null,
            'max_players' => is_numeric($cache['max_players'] ?? null) ? max(0, (int) $cache['max_players']) : null,
            'map' => is_string($cache['map'] ?? null) ? $cache['map'] : null,
            'last_checked_at' => $server->getLastCheckedAt(), 'last_error' => null,
        ];
    }
}
