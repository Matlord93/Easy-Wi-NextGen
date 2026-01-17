<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Setup\Runtime\DatabaseConfig;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

DatabaseConfig::boot(dirname(__DIR__));

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
