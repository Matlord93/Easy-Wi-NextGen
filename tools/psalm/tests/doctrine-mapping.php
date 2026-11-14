<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$toolsDirectory = dirname(__DIR__);
$coreDirectory = dirname($toolsDirectory, 2).'/core';
require $coreDirectory.'/vendor/autoload.php';
$temporaryDirectory = sys_get_temp_dir().'/easywi-psalm-mapping-'.bin2hex(random_bytes(8));
mkdir($temporaryDirectory, 0700);

try {
    $configuration = new SimpleXMLElement('<psalm xmlns="https://getpsalm.org/schema/config"/>');
    $configuration->addAttribute('errorLevel', '1');
    $configuration->addAttribute('findUnusedCode', 'true');
    $configuration->addAttribute('phpVersion', '8.5');
    $configuration->addAttribute('autoloader', $coreDirectory.'/vendor/autoload.php');
    $configuration->addChild('projectFiles')->addChild('file')->addAttribute('name', $toolsDirectory.'/tests/fixtures/DoctrineFields.php');
    $configuration->addChild('plugins')->addChild('plugin')->addAttribute('filename', $toolsDirectory.'/plugins/DoctrineMappingPlugin.php');
    $configurationPath = $temporaryDirectory.'/psalm.xml';
    $configuration->asXML($configurationPath);

    $process = new Process([PHP_BINARY, $toolsDirectory.'/vendor/bin/psalm', '--config='.$configurationPath, '--no-cache', '--no-diff', '--output-format=json'], $coreDirectory);
    $process->setTimeout(120);
    $process->run();
    $issues = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    $unusedProperties = [];
    foreach ($issues as $issue) {
        if ($issue['type'] !== 'UnusedProperty') {
            throw new RuntimeException('Unexpected mapping analysis issue: '.$issue['message']);
        }
        $unusedProperties[] = $issue['selected_text'];
    }
    sort($unusedProperties);
    if ($process->getExitCode() !== 2 || $unusedProperties !== ['$notEntity', '$unmapped']) {
        throw new RuntimeException('Doctrine mapping regression: '.$process->getOutput().$process->getErrorOutput());
    }
    echo "Doctrine mapping: persisted fields recognized; unused fields remain detectable.\n";
} finally {
    if (is_file($temporaryDirectory.'/psalm.xml')) {
        unlink($temporaryDirectory.'/psalm.xml');
    }
    rmdir($temporaryDirectory);
}
