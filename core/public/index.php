<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Setup\Application\InstallEnvBootstrap;

require_once dirname(__DIR__) . '/src/Module/Setup/Application/KeyMaterialGenerator.php';
require_once dirname(__DIR__) . '/src/Module/Setup/Application/EnvFileWriter.php';
require_once dirname(__DIR__) . '/src/Module/Setup/Application/InstallEnvBootstrap.php';

$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (str_starts_with($requestPath, '/install')) {
    $bootstrap = new InstallEnvBootstrap();
    $status = $bootstrap->ensure(dirname(__DIR__));
    if (!($status['ok'] ?? false)) {
        $_SERVER['INSTALL_ENV_BOOTSTRAP_ERROR'] = '1';
        $_ENV['INSTALL_ENV_BOOTSTRAP_ERROR'] = '1';
        $_SERVER['INSTALL_ENV_BOOTSTRAP_PATH'] = (string) ($status['env_path'] ?? dirname(__DIR__) . '/.env.local');
        $_ENV['INSTALL_ENV_BOOTSTRAP_PATH'] = (string) ($status['env_path'] ?? dirname(__DIR__) . '/.env.local');
    }
}

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return static function (array $context): Kernel {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
