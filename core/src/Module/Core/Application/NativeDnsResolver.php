<?php
declare(strict_types=1);
namespace App\Module\Core\Application;

final class NativeDnsResolver implements DnsResolverInterface
{
    public function query(string $host, int $type): array
    {
        $records = @dns_get_record($host, $type);
        return is_array($records) ? $records : [];
    }
}
