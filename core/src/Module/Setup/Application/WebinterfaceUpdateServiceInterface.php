<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Core\Update\UpdateResult;

interface WebinterfaceUpdateServiceInterface
{
    public function applyUpdate(): UpdateResult;

    public function applyMigrations(): UpdateResult;
}
