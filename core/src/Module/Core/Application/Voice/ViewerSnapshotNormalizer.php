<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Voice;

final class ViewerSnapshotNormalizer
{
    /**
     * @param array<string,mixed> $payload
     * @return array{server: array<string,mixed>, channels: array<int,array<string,mixed>>, clients: array<int,array<string,mixed>>}
     */
    public static function normalize(array $payload): array
    {
        $snapshot = self::extractSnapshot($payload);
        $server = is_array($snapshot['server'] ?? null) ? $snapshot['server'] : [];
        $channels = self::normalizeChannels(self::asArray($snapshot['channels'] ?? []));
        $clients = self::normalizeClients(self::asArray($snapshot['clients'] ?? []));

        return [
            'server' => $server,
            'channels' => $channels,
            'clients' => $clients,
        ];
    }

    /** @return array<string,mixed> */
    private static function extractSnapshot(array $payload): array
    {
        foreach (['snapshot', 'payload', 'data', 'viewer', 'resultPayload', 'result'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                return $payload[$key];
            }
        }
        return $payload;
    }

    /** @param array<int,mixed> $channels @return array<int,array<string,mixed>> */
    private static function normalizeChannels(array $channels): array
    {
        $out = [];
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $id = self::firstString($channel, ['id', 'cid', 'channel_id']);
            if ($id === '') {
                continue;
            }
            $parent = self::firstString($channel, ['parentId', 'pid', 'cpid', 'parent_id']);
            $name = self::firstString($channel, ['name', 'channel_name']) ?: 'Channel';
            $order = self::firstIntOrNull($channel, ['order', 'channel_order']);
            $out[] = ['id' => $id, 'parentId' => ($parent === '' || $parent === '0') ? null : $parent, 'name' => $name, 'order' => $order];
        }
        return $out;
    }

    /** @param array<int,mixed> $clients @return array<int,array<string,mixed>> */
    private static function normalizeClients(array $clients): array
    {
        $out = [];
        foreach ($clients as $client) {
            if (!is_array($client)) {
                continue;
            }
            if ((int)($client['client_type'] ?? $client['type'] ?? 0) === 1) {
                continue;
            }
            $id = self::firstString($client, ['id', 'clid', 'client_id']);
            $channelId = self::firstString($client, ['channelId', 'cid', 'channel_id']);
            if ($id === '' || $channelId === '') {
                continue;
            }
            $nickname = self::firstString($client, ['nickname', 'client_nickname', 'name']) ?: 'User';
            $out[] = ['id' => $id, 'channelId' => $channelId, 'nickname' => $nickname];
        }
        return $out;
    }

    /** @param array<string,mixed> $row @param array<int,string> $keys */
    private static function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $row @param array<int,string> $keys */
    private static function firstIntOrNull(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                continue;
            }
            return (int)$row[$key];
        }
        return null;
    }

    /** @return array<int,mixed> */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}

