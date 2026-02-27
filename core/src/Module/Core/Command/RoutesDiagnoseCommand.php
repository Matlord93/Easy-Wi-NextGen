<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(
    name: 'app:diagnose:routes',
    description: 'List active filemanager/gameserver/sftp routes and selected data-flow strategy.',
)]
final class RoutesDiagnoseCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $collection = $this->router->getRouteCollection();
        } catch (LoaderLoadException $exception) {
            $io->error([
                'Unable to build the route collection from attribute routes.',
                $exception->getMessage(),
                'Run "composer dump-autoload" and clear Symfony cache (var/cache/*) on the target host, then retry.',
            ]);

            return Command::FAILURE;
        }

        $rows = [];
        foreach ($collection->all() as $name => $route) {
            $path = $route->getPath();
            if (!str_contains($name, 'file')
                && !str_contains($name, 'sftp')
                && !str_contains($name, 'instance')
                && !str_contains($path, '/files')
                && !str_contains($path, '/sftp')
                && !str_contains($path, '/instances')) {
                continue;
            }

            $rows[] = [
                $name,
                implode('|', $route->getMethods() ?: ['ANY']),
                $path,
                (string) $route->getDefault('_controller'),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));

        $io->title('Route diagnostics');
        $io->text('Active file flow: Core -> FileServiceClient -> Agent file API.');
        $io->table(['Route', 'Methods', 'Path', 'Controller'], $rows);

        return Command::SUCCESS;
    }
}
