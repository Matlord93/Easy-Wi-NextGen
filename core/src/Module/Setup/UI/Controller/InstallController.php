<?php

declare(strict_types=1);

namespace App\Module\Setup\UI\Controller;

use App\Module\Setup\Application\InstallerService;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class InstallController
{
    public function __construct(
        private readonly InstallerService $installerService,
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route(path: '/install', name: 'public_install', methods: ['GET', 'POST'])]
    public function install(Request $request): Response
    {
        $this->installerService->resolveInstallerLocale($request);

        if ($this->installerService->isLocked()) {
            return new Response($this->twig->render('install/already_installed.html.twig', [
                'step' => 1,
                'loginUrl' => '/login?lang=' . $request->getLocale(),
            ]), Response::HTTP_FORBIDDEN);
        }

        $state = $this->installerService->readState();
        $stepParam = $request->query->get('step');
        $step = max(1, min(4, (int) ($stepParam ?? 1)));
        $errors = [];
        $success = [];
        $dbVersion = $state['database']['version'] ?? null;
        $requirements = $this->installerService->checkRequirements();
        $requirementsOk = $this->installerService->requirementsSatisfied($requirements);
        $debugAvailable = file_exists($this->installerService->getDebugReportPath());

        $databaseDefaults = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'name' => 'easywi',
            'user' => '',
            'password' => '',
            'path' => $this->projectDir . '/srv/setup/data/data.db',
        ];
        $applicationDefaults = [
            'site_name' => 'Default Site',
            'site_host' => $request->getHost() ?: 'localhost',
            'admin_name' => '',
            'admin_email' => '',
        ];
        $settingsDefaults = [
            'instance_base_dir' => '/home',
            'sftp_host' => '',
            'sftp_port' => '22',
            'sftp_username' => '',
            'sftp_password' => '',
            'sftp_private_key' => '',
            'sftp_private_key_path' => '',
            'sftp_private_key_passphrase' => '',
        ];

        $databaseState = array_merge($databaseDefaults, $state['database'] ?? []);
        $applicationState = array_merge($applicationDefaults, $state['application'] ?? []);
        $settingsState = array_merge($settingsDefaults, $state['settings'] ?? []);
        $storedPassword = $databaseState['password'] ?? '';
        $databaseState['password'] = '';

        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            $action = (string) ($payload['action'] ?? '');
            $postedStep = (int) ($payload['step'] ?? $step);
            $step = max(1, min(4, $postedStep));

            if ($step === 1 && $action === 'continue') {
                $step = 2;
            }

            elseif ($step === 2) {
                $requirements = $this->installerService->checkRequirements();
                $requirementsOk = $this->installerService->requirementsSatisfied($requirements);

                if ($action === 'continue') {
                    if ($requirementsOk) {
                        $step = 3;
                    } else {
                        $errors[] = ['key' => 'errors.requirements_not_met'];
                    }
                }
            }

            elseif ($step === 3) {
                $databaseState = array_merge($databaseState, [
                    'driver' => (string) ($payload['db_driver'] ?? $databaseState['driver']),
                    'host' => trim((string) ($payload['db_host'] ?? $databaseState['host'])),
                    'port' => trim((string) ($payload['db_port'] ?? $databaseState['port'])),
                    'name' => trim((string) ($payload['db_name'] ?? $databaseState['name'])),
                    'user' => trim((string) ($payload['db_user'] ?? $databaseState['user'])),
                    'password' => (string) ($payload['db_password'] ?? ''),
                    'path' => trim((string) ($payload['db_path'] ?? $databaseState['path'])),
                ]);

                if ($databaseState['password'] === '' && $storedPassword !== '') {
                    $databaseState['password'] = $storedPassword;
                }

                $validationErrors = $this->installerService->validateDatabaseInput($databaseState);
                foreach ($validationErrors as $validationError) {
                    $errors[] = $validationError;
                }

                if ($errors === [] && in_array($action, ['test_connection', 'test_privileges', 'continue'], true)) {
                    $dbConfig = null;
                    $dbVersion = null;
                    $connectionOk = false;
                    $attemptedHost = $databaseState['host'];
                    $fallbackHost = null;

                    if ($databaseState['driver'] === 'mysql') {
                        if ($databaseState['host'] === '127.0.0.1') {
                            $fallbackHost = 'localhost';
                        } elseif ($databaseState['host'] === 'localhost') {
                            $fallbackHost = '127.0.0.1';
                        }
                    }

                    try {
                        $dbConfig = $this->installerService->buildDatabaseConfig($databaseState);
                        $dbVersion = $this->installerService->testDbConnection($dbConfig);
                        $connectionOk = true;
                    } catch (DbalException|\Throwable $exception) {
                        if ($fallbackHost !== null) {
                            $attemptedHost = $fallbackHost;
                            $databaseState['host'] = $fallbackHost;
                            $dbConfig = $this->installerService->buildDatabaseConfig($databaseState);

                            try {
                                $dbVersion = $this->installerService->testDbConnection($dbConfig);
                                $connectionOk = true;
                            } catch (DbalException|\Throwable $fallbackException) {
                                $this->installerService->logException($fallbackException, 'Database connection failed during installer.');
                                $errors[] = ['key' => 'errors.db_connection_failed'];
                            }
                        } else {
                            $this->installerService->logException($exception, 'Database connection failed during installer.');
                            $errors[] = ['key' => 'errors.db_connection_failed'];
                        }
                    }

                    if ($errors === [] && $connectionOk) {
                        $databaseState['version'] = $dbVersion ?? null;
                        $databaseState['host'] = $attemptedHost;
                        $success[] = ['key' => 'messages.db_connection_success'];

                        if ($action === 'test_privileges') {
                            try {
                                $this->installerService->testDbPrivileges($dbConfig);
                                $success[] = ['key' => 'messages.db_privileges_success'];
                            } catch (DbalException $exception) {
                                $this->installerService->logException($exception, 'Database privilege test failed during installer.');
                                $errors[] = ['key' => 'errors.db_privileges_failed'];
                            } catch (\Throwable $exception) {
                                $this->installerService->logException($exception, 'Database privilege test failed during installer.');
                                $errors[] = ['key' => 'errors.db_privileges_failed'];
                            }
                        }

                        if ($action === 'continue' && $errors === []) {
                            $step = 4;
                        }
                    }
                }

                if ($action === 'back') {
                    $step = 2;
                }
            }

            elseif ($step === 4) {
                $applicationState = array_merge($applicationState, [
                    'site_name' => trim((string) ($payload['site_name'] ?? $applicationState['site_name'])),
                    'site_host' => trim((string) ($payload['site_host'] ?? $applicationState['site_host'])),
                    'admin_name' => trim((string) ($payload['admin_name'] ?? $applicationState['admin_name'])),
                    'admin_email' => trim((string) ($payload['admin_email'] ?? $applicationState['admin_email'])),
                ]);
                $settingsState = array_merge($settingsState, [
                    'instance_base_dir' => trim((string) ($payload['instance_base_dir'] ?? $settingsState['instance_base_dir'])),
                    'sftp_host' => trim((string) ($payload['sftp_host'] ?? $settingsState['sftp_host'])),
                    'sftp_port' => trim((string) ($payload['sftp_port'] ?? $settingsState['sftp_port'])),
                    'sftp_username' => trim((string) ($payload['sftp_username'] ?? $settingsState['sftp_username'])),
                    'sftp_password' => (string) ($payload['sftp_password'] ?? $settingsState['sftp_password']),
                    'sftp_private_key' => (string) ($payload['sftp_private_key'] ?? $settingsState['sftp_private_key']),
                    'sftp_private_key_path' => trim((string) ($payload['sftp_private_key_path'] ?? $settingsState['sftp_private_key_path'])),
                    'sftp_private_key_passphrase' => (string) ($payload['sftp_private_key_passphrase'] ?? $settingsState['sftp_private_key_passphrase']),
                ]);

                if ($databaseState['password'] === '' && $storedPassword !== '') {
                    $databaseState['password'] = $storedPassword;
                }

                $adminPassword = (string) ($payload['admin_password'] ?? '');
                $adminPasswordConfirm = (string) ($payload['admin_password_confirm'] ?? '');

                if ($applicationState['site_name'] === '' || $applicationState['site_host'] === '') {
                    $errors[] = ['key' => 'errors.site_required'];
                }

                if ($applicationState['admin_name'] === '') {
                    $errors[] = ['key' => 'errors.admin_name_required'];
                }

                if ($applicationState['admin_email'] === '' || !filter_var($applicationState['admin_email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = ['key' => 'errors.email_invalid'];
                }

                if (mb_strlen($adminPassword) < 8) {
                    $errors[] = ['key' => 'errors.password_length'];
                }

                if ($adminPassword !== $adminPasswordConfirm) {
                    $errors[] = ['key' => 'errors.password_mismatch'];
                }

                if ($action === 'back') {
                    $step = 3;
                }

                if ($errors === [] && $action === 'install') {
                    $validationErrors = $this->installerService->validateDatabaseInput($databaseState);
                    foreach ($validationErrors as $validationError) {
                        $errors[] = $validationError;
                    }

                    if ($errors !== []) {
                        $step = 3;
                    } else {
                        $requirements = $this->installerService->checkRequirements();
                        $requirementsOk = $this->installerService->requirementsSatisfied($requirements);

                        if (!$requirementsOk) {
                            $errors[] = ['key' => 'errors.requirements_not_met'];
                        } else {
                            try {
                                $dbConfig = $this->installerService->buildDatabaseConfig($databaseState);
                                $this->installerService->testDbConnection($dbConfig);
                                $databaseUrl = $this->installerService->buildDatabaseUrl($databaseState);

                                $this->installerService->storeDatabaseConfig($databaseUrl, $databaseState);

                                $entityManager = $this->installerService->createInstallEntityManager($dbConfig['connection']);
                                $this->installerService->runMigrations($entityManager);
                                $this->installerService->seedTemplates($entityManager);
                                $entityManager->clear();
                                $entityManager->getConnection()->close();
                                $entityManager = $this->installerService->createInstallEntityManager($dbConfig['connection']);
                                $this->installerService->createSiteAndAdmin($entityManager, $applicationState, $adminPassword);
                                $this->installerService->storeAppSettings($entityManager, $settingsState);

                                $this->installerService->writeLock();
                                $this->installerService->clearState();
                                $this->installerService->clearDebugReport();

                                $loginUrl = '/login?lang=' . $request->getLocale();

                                return new Response($this->twig->render('install/success.html.twig', [
                                    'loginUrl' => $loginUrl,
                                    'step' => 4,
                                ]));
                            } catch (DbalException $exception) {
                                $this->installerService->logException($exception, 'Database connection failed during installation.');
                                $errors[] = ['key' => 'errors.db_connection_failed'];
                            } catch (\Throwable $exception) {
                                $this->installerService->logException($exception, 'Installer failed during install step.');
                                $errors[] = ['key' => 'errors.install_failed'];
                            }
                        }
                    }
                }
            }

            $databaseState['password'] = $databaseState['password'] ?? '';
            $this->installerService->writeState([
                'step' => $step,
                'database' => $databaseState,
                'application' => $applicationState,
                'settings' => $settingsState,
            ]);

            if ($errors !== []) {
                $this->installerService->writeDebugReport($this->buildDebugReport(
                    $requirements,
                    $databaseState,
                    $applicationState,
                    $settingsState,
                    $errors,
                ));
                $debugAvailable = true;
            }
        }

        $viewDatabase = $databaseState;
        $viewDatabase['password'] = '';

        $template = match ($step) {
            1 => 'install/step1.html.twig',
            2 => 'install/step2_requirements.html.twig',
            3 => 'install/step3_database.html.twig',
            default => 'install/step4_app.html.twig',
        };

        return new Response($this->twig->render($template, [
            'step' => $step,
            'errors' => $errors,
            'success' => $success,
            'database' => $viewDatabase,
            'application' => $applicationState,
            'settings' => $settingsState,
            'requirements' => $requirements,
            'requirementsOk' => $requirementsOk,
            'phpVersion' => PHP_VERSION,
            'phpExtensions' => $this->installerService->getPhpExtensions(),
            'dbVersion' => $dbVersion,
            'hasStoredPassword' => $storedPassword !== '',
            'debugAvailable' => $debugAvailable,
        ]));
    }

    #[Route(path: '/install/debug', name: 'public_install_debug', methods: ['GET'])]
    public function debugReport(Request $request): Response
    {
        $this->installerService->resolveInstallerLocale($request);

        if ($this->installerService->isLocked()) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $path = $this->installerService->getDebugReportPath();
        if (!file_exists($path)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new BinaryFileResponse($path);
    }

    /**
     * @param array<int, array<string, mixed>> $requirements
     * @param array<int, array<string, mixed>> $errors
     * @param array<string, mixed> $databaseState
     * @param array<string, mixed> $applicationState
     * @param array<string, mixed> $settingsState
     *
     * @return array<string, mixed>
     */
    private function buildDebugReport(
        array $requirements,
        array $databaseState,
        array $applicationState,
        array $settingsState,
        array $errors,
    ): array
    {
        $databaseState['password'] = '***';
        if (array_key_exists('sftp_password', $settingsState)) {
            $settingsState['sftp_password'] = '***';
        }
        if (array_key_exists('sftp_private_key', $settingsState)) {
            $settingsState['sftp_private_key'] = '***';
        }
        if (array_key_exists('sftp_private_key_passphrase', $settingsState)) {
            $settingsState['sftp_private_key_passphrase'] = '***';
        }

        return [
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'php_version' => PHP_VERSION,
            'extensions' => $this->installerService->getPhpExtensions(),
            'requirements' => $requirements,
            'database' => $databaseState,
            'application' => $applicationState,
            'settings' => $settingsState,
            'errors' => $errors,
        ];
    }
}
