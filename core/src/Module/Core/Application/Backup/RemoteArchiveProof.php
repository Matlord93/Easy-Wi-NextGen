<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final readonly class RemoteArchiveProof
{
    public function __construct(public int $size, public string $sha256, public string $version)
    {
    }
}
