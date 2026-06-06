<?php

declare(strict_types=1);

use App\Infrastructure\Runtime\MemoryLimit;
use App\Kernel;
use App\Module\Setup\Application\InstallEnvBootstrap;

require_once dirname(__DIR__) . '/src/Infrastructure/Runtime/MemoryLimit.php';
require_once dirname(__DIR__) . '/src/Module/Setup/Application/KeyMaterialGenerator.php';
require_once dirname(__DIR__) . '/src/Module/Setup/Application/EnvFileWriter.php';
require_once dirname(__DIR__) . '/src/Module/Setup/Application/InstallEnvBootstrap.php';

MemoryLimit::ensureMinimum();

$updateMaintenanceFile = dirname(__DIR__) . '/var/update-maintenance.flag';
if (is_file($updateMaintenanceFile)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Retry-After: 30');

    $message = trim((string) @file_get_contents($updateMaintenanceFile));
    if ($message === '') {
        $message = 'Easy-Wi is being updated. Please retry in a moment.';
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Maintenance</title></head>'
        . '<body style="font-family: system-ui, sans-serif; margin: 3rem; color: #0f172a; background: #f8fafc;">'
        . '<main style="max-width: 42rem;"><h1>Maintenance</h1><p>'
        . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p></main></body></html>';
    exit;
}

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
