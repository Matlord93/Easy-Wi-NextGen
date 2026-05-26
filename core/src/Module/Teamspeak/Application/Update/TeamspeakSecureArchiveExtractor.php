<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

final class TeamspeakSecureArchiveExtractor
{
    public function extract(string $archiveFile, string $targetDir): void
    {
        if (!is_file($archiveFile)) { throw new \RuntimeException('Archiv fehlt.'); }
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0770, true) && !is_dir($targetDir)) { throw new \RuntimeException('Zielverzeichnis nicht erstellbar.'); }
        if (str_ends_with($archiveFile, '.zip')) {
            $this->extractZip($archiveFile, $targetDir); return;
        }
        if (str_ends_with($archiveFile, '.tar.xz')) {
            $this->extractTarXz($archiveFile, $targetDir); return;
        }
        throw new \RuntimeException('Nicht unterstütztes Archivformat.');
    }
    private function extractZip(string $archiveFile, string $targetDir): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($archiveFile) !== true) { throw new \RuntimeException('ZIP konnte nicht geöffnet werden.'); }
        for ($i=0; $i<$zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertSafePath($name);
        }
        if (!$zip->extractTo($targetDir)) { $zip->close(); throw new \RuntimeException('ZIP entpacken fehlgeschlagen.'); }
        $zip->close();
    }
    private function extractTarXz(string $archiveFile, string $targetDir): void
    {
        $escapedArchive = escapeshellarg($archiveFile);
        $escapedTarget = escapeshellarg($targetDir);
        $listOutput = []; $listCode = 0;
        exec('tar -tJf '.$escapedArchive, $listOutput, $listCode);
        if ($listCode !== 0) { throw new \RuntimeException('TAR konnte nicht gelesen werden.'); }
        foreach ($listOutput as $entry) { $this->assertSafePath((string) $entry); }
        $code = 0; exec('tar -xJf '.$escapedArchive.' -C '.$escapedTarget, $out, $code);
        if ($code !== 0) { throw new \RuntimeException('TAR entpacken fehlgeschlagen.'); }
    }
    private function assertSafePath(string $path): void
    {
        $p = str_replace('\\', '/', $path);
        if ($p === '' || str_starts_with($p, '/') || str_contains($p, '../') || str_starts_with($p, '../')) { throw new \RuntimeException('Unsicherer Archivpfad erkannt: '.$path); }
    }
}
