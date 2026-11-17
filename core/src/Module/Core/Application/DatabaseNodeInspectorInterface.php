<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Database;

interface DatabaseNodeInspectorInterface
{
    /** @return array{database_exists:bool,user_exists:bool,grants_ok:bool} */
    public function inspect(Database $database): array;
}
