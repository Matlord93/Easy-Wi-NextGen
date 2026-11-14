<?php
declare(strict_types=1);
namespace App\Module\Core\Application;

use App\Infrastructure\Config\JsonArray;
final class NativeDnsResolver implements DnsResolverInterface
{
    #[\Override]
    public function query(string $host, int $type): array
    {
        $records = @dns_get_record($host, $type);
        return JsonArray::orEmpty($records);
    }
}
