<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Module\Core\Application\PrivacyGdprBackgroundJobService;
use App\Module\Core\Command\GdprExportProcessCommand;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GdprExportProcessIntegrationTest extends KernelTestCase
{
    public function testProcessesAnEmptyQueueWithTheRequestedLimit(): void
    {
        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::ensureKernelShutdown();

        try {
            self::bootKernel();
            $container = self::getContainer();
            $entityManager = $container->get(EntityManagerInterface::class);
            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
            $command = new GdprExportProcessCommand($container->get(PrivacyGdprBackgroundJobService::class));
            $tester = new CommandTester($command);

            self::assertSame(0, $tester->execute(['--limit' => '0']));
            self::assertStringContainsString('No pending GDPR exports.', $tester->getDisplay());
            self::assertSame('25', $command->getDefinition()->getOption('limit')->getDefault());
        } finally {
            self::ensureKernelShutdown();
            foreach (['SERVER', 'ENV'] as $index => $global) {
                if ($previous[$index] === null) {
                    unset($GLOBALS['_'.$global]['DATABASE_URL']);
                } else {
                    $GLOBALS['_'.$global]['DATABASE_URL'] = $previous[$index];
                }
            }
        }
    }
}
