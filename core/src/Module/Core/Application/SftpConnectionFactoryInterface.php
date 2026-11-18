<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use phpseclib3\Net\SFTP;

interface SftpConnectionFactoryInterface
{
    public function create(string $host, int $port): SFTP;
}
