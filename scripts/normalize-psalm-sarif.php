<?php

declare(strict_types=1);

// Psalm 7.4 emits an empty helpUri for some plugin rules. SARIF permits the
// property to be absent, but an explicitly empty URI fails GitHub validation.
// Preserve every rule and result; only remove this invalid optional property.
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php normalize-psalm-sarif.php <report.sarif>\n");
    exit(1);
}

try {
    $path = $argv[1];
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read the SARIF report.');
    }

    $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($report) || !isset($report['runs']) || !is_array($report['runs'])) {
        throw new RuntimeException('Invalid SARIF report: missing runs.');
    }

    foreach ($report['runs'] as &$run) {
        if (!isset($run['tool']['driver']['rules']) || !is_array($run['tool']['driver']['rules'])) {
            continue;
        }
        foreach ($run['tool']['driver']['rules'] as &$rule) {
            if (isset($rule['helpUri']) && is_string($rule['helpUri']) && trim($rule['helpUri']) === '') {
                unset($rule['helpUri']);
            }
        }
        unset($rule);
    }
    unset($run);

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($path, $encoded) === false) {
        throw new RuntimeException('Cannot write the normalized SARIF report.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
