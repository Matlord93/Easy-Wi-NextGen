<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Template;

interface SharedStorageTemplateLocatorInterface
{
    public function findSharedStorageVariantForIdentity(Template $template): ?Template;
}
