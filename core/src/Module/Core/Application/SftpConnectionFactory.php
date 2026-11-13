<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use phpseclib3\Net\SFTP;

class SftpConnectionFactory
{
    public function create(string $host, int $port): SFTP
    {
        return new SFTP($host, $port, 10);
    }
}
