<?php
declare(strict_types=1);
namespace App\Module\Core\Application;

interface DnsResolverInterface
{
    /** @return array<int,array<string,mixed>> */
    public function query(string $host, int $type): array;
}
