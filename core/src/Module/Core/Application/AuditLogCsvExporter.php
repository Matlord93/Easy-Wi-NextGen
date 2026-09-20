<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class AuditLogCsvExporter
{
    /** @param array<int, array<string, mixed>> $rows */
    public function export(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (false === $stream) {
            throw new \RuntimeException('Unable to create audit CSV stream.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['id', 'created_at', 'actor', 'actor_type', 'action', 'payload', 'hash_prev', 'hash_current'], ';', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, [
                (string) ($row['id'] ?? ''), (string) ($row['created_at'] ?? ''),
                $this->safeCell((string) ($row['actor_email'] ?? 'System')),
                $this->safeCell((string) ($row['actor_type'] ?? '')),
                $this->safeCell((string) ($row['action'] ?? '')),
                $this->safeCell((string) ($row['payload_preview'] ?? '{}')),
                (string) ($row['hash_prev'] ?? ''), (string) ($row['hash_current'] ?? ''),
            ], ';', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return false === $contents ? '' : $contents;
    }

    private function safeCell(string $value): string
    {
        $trimmed = ltrim($value);
        return '' !== $trimmed && in_array($trimmed[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
