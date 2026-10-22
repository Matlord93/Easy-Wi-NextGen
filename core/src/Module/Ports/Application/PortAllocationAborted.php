<?php

namespace App\Module\Ports\Application;

final class PortAllocationAborted extends \RuntimeException
{
    public function __construct(public readonly \Symfony\Component\HttpFoundation\Response $response)
    {
        parent::__construct('Port allocation rolled back.');
    }
}
