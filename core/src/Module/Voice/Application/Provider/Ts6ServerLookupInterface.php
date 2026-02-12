<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

interface Ts6ServerLookupInterface
{
    /** @return array{status:string,public_host:?string,voice_port:?int,slots:int}|null */
    public function find(string $externalId): ?array;
}
