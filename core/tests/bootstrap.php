<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Concurrent PHPUnit processes must not rebuild or delete each other's schema.
// Explicit per-test connection overrides (for example sqlite::memory:) still win.
$testDatabase = dirname(__DIR__).'/var/test-'.getmypid().'.db';
$testDatabaseUrl = 'sqlite:///'.str_replace('\\', '/', $testDatabase);
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $testDatabaseUrl;
putenv('DATABASE_URL='.$testDatabaseUrl);
register_shutdown_function(static function () use ($testDatabase): void {
    foreach ([$testDatabase, $testDatabase.'-shm', $testDatabase.'-wal'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

// Keep update fixtures inside this checkout, including when the project is cloned
// into a different directory from a developer's existing installation.
$updateTestRoot = dirname(__DIR__).'/var/test-runtime';
foreach ([
    'APP_CORE_UPDATE_RELEASES_DIR' => $updateTestRoot.'/releases',
    'APP_CORE_UPDATE_CURRENT_SYMLINK' => $updateTestRoot.'/current',
    'APP_CORE_UPDATE_LOCK_FILE' => $updateTestRoot.'/tmp/webinterface_update.lock',
    'APP_CORE_UPDATE_INSTALL_DIR' => $updateTestRoot.'/core',
    'APP_CORE_UPDATE_JOBS_DIR' => $updateTestRoot.'/jobs',
    'APP_CORE_UPDATE_LOGS_DIR' => $updateTestRoot.'/logs',
    'APP_CORE_UPDATE_BACKUPS_DIR' => $updateTestRoot.'/backups',
] as $name => $value) {
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv($name.'='.$value);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
