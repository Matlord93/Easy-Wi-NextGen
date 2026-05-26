<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Update;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\TeamspeakUpdateLog;
use App\Module\Core\Domain\Entity\Ts6Instance;
use App\Module\Core\Domain\Entity\User;
use App\Repository\TeamspeakUpdateLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final class TeamspeakUpdateInstallerService
{
    public function __construct(
        private readonly TeamspeakUpdateService $updateService,
        private readonly TeamspeakUpdateLockManager $lockManager,
        private readonly TeamspeakUpdateDownloadService $downloadService,
        private readonly TeamspeakSecureArchiveExtractor $extractor,
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentJobDispatcher $jobDispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly TeamspeakUpdateLogRepository $updateLogRepository,
        private readonly string $projectDir,
        private readonly bool $requireChecksum = false,
    ) {}

    public function installTs6(Ts6Instance $instance, User $actor): TeamspeakUpdateLog
    {
        $id = $instance->getId();
        if ($id === null) { throw new \RuntimeException('Instance not persisted.'); }
        if (!$this->lockManager->acquire('ts6', $id, 3600)) { throw new \RuntimeException('Update läuft bereits für diese Instanz.'); }

        $check = $this->updateService->checkTs6($instance);
        $log = new TeamspeakUpdateLog('ts6', $id, $instance->getInstalledVersion(), $check->availableVersion, $actor);
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        try {
            if (!$check->updateAvailable || $check->assetUrl === null) {
                $log->setStatus('skipped');
                $log->addStep('validate_update', 'skip', $check->status);
                $log->setEndedAt(new \DateTimeImmutable());
                $this->entityManager->flush();
                return $log;
            }

            $log->setStatus('running');
            $log->setDownloadUrl($check->assetUrl);
            $log->addStep('validate_update');

            $installPath = (string) $instance->getInstallPath();
            if ($installPath === '') { throw new \RuntimeException('Installationspfad fehlt.'); }
            $baseUpdateDir = rtrim($this->projectDir, '/').'/var/teamspeak-updates';
            $runDir = sprintf('%s/ts6/%d/%s', $baseUpdateDir, $id, (new \DateTimeImmutable())->format('YmdHis'));
            $backupDir = $runDir.'/backup'; $extractDir = $runDir.'/extract'; $downloadFile = $runDir.'/package'.(str_ends_with($check->assetUrl,'.zip')?'.zip':'.tar.xz');
            @mkdir($backupDir, 0770, true);
            @mkdir($extractDir, 0770, true);

            $this->copyDirectory($installPath, $backupDir);
            $log->setBackupPath($backupDir);
            $log->addStep('backup');

            $stopJob = $this->jobDispatcher->dispatch($instance->getNode(), 'ts6.instance.action', ['instance_id'=>(string)$id,'customer_id'=>(string)$instance->getCustomer()->getId(),'node_id'=>$instance->getNode()->getId(),'service_name'=>sprintf('ts6-%d',$id),'action'=>'stop']);
            $log->addStep('stop_requested', 'ok', 'job_id='.(string)$stopJob->getId().' dispatch-only; no synchronous confirmation');

            try {
                $this->downloadService->download($check->assetUrl, $downloadFile);
            } catch (\Throwable $e) {
                $log->addStep(
                    'download_failed',
                    'error',
                    sprintf(
                        'release_tag=%s asset_name=%s asset_url=%s error=%s',
                        (string) ($check->releaseTag ?? '-'),
                        (string) ($check->assetName ?? '-'),
                        $check->assetUrl,
                        $e->getMessage()
                    )
                );
                throw new \RuntimeException('teamspeak.update.download_failed', 0, $e);
            }
            $log->addStep('download');

            $expectedSha = $check->checksum?->value;
            if ($expectedSha !== null && $expectedSha !== '') {
                $log->addStep('checksum_resolved', 'ok', sprintf('%s:%s (%s)', $check->checksum->algorithm ?? 'sha256', $expectedSha, $check->checksum->source ?? 'unknown'));
            } else {
                $log->addStep('checksum_missing', $this->requireChecksum ? 'error' : 'warning', 'Keine Checksum verfügbar.');
            }
            $verify = $this->downloadService->verifySha256($downloadFile, $expectedSha, $this->requireChecksum);
            if ($verify->missing && $this->requireChecksum) {
                throw new \RuntimeException('Checksum fehlt im Strict-Mode.');
            }
            if (!$verify->missing && !$verify->verified) {
                $log->addStep('checksum_failed', 'error', $verify->message);
                throw new \RuntimeException('Checksum-Validierung fehlgeschlagen.');
            }
            if ($verify->verified) {
                $log->addStep('checksum_verified', 'ok', $verify->actual);
            }

            $this->extractor->extract($downloadFile, $extractDir);
            $log->addStep('extract');

            $source = $this->resolveExtractRoot($extractDir);
            $this->copyDirectory($source, $installPath, ['ts3server.sqlitedb', 'files', 'logs']);
            $log->addStep('replace_binaries');

            $startJob = $this->jobDispatcher->dispatch($instance->getNode(), 'ts6.instance.action', ['instance_id'=>(string)$id,'customer_id'=>(string)$instance->getCustomer()->getId(),'node_id'=>$instance->getNode()->getId(),'service_name'=>sprintf('ts6-%d',$id),'action'=>'start']);
            $log->addStep('start_requested', 'ok', 'job_id='.(string)$startJob->getId().' dispatch-only; no synchronous confirmation');

            $instance->setInstalledVersion($check->availableVersion);
            $instance->setAvailableVersion($check->availableVersion);
            $log->setStatus('success');
            $log->setEndedAt(new \DateTimeImmutable());
            $this->auditLogger->log($actor, 'ts6.update_installed', ['instance_id' => $id, 'update_log_id' => $log->getId(), 'version' => $check->availableVersion]);
            $this->entityManager->flush();

            return $log;
        } catch (\Throwable $e) {
            $log->setStatus('failed');
            $log->setErrorMessage($e->getMessage());
            $log->setErrorDetails($e::class);
            $log->addStep('failed', 'error', $e->getMessage());
            $log->setEndedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
            try {
                $restartJob = $this->jobDispatcher->dispatch($instance->getNode(), 'ts6.instance.action', ['instance_id'=>(string)$id,'customer_id'=>(string)$instance->getCustomer()->getId(),'node_id'=>$instance->getNode()->getId(),'service_name'=>sprintf('ts6-%d',$id),'action'=>'start']);
                $log->addStep('start_requested', 'warning', 'recovery job_id='.(string)$restartJob->getId());
                $this->entityManager->flush();
            } catch (\Throwable) {}
            throw $e;
        } finally {
            $this->lockManager->release('ts6', $id);
        }
    }

    private function resolveExtractRoot(string $extractDir): string
    {
        $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn(string $x): bool => !in_array($x, ['.', '..'], true)));
        if (count($entries) === 1 && is_dir($extractDir.'/'.$entries[0])) { return $extractDir.'/'.$entries[0]; }
        return $extractDir;
    }

    private function copyDirectory(string $source, string $target, array $preservePaths = []): void
    {
        if (!is_dir($source)) { throw new \RuntimeException('Source directory missing: '.$source); }
        if (!is_dir($target) && !@mkdir($target, 0770, true) && !is_dir($target)) { throw new \RuntimeException('Target directory not writable: '.$target); }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = ltrim(str_replace($source, '', $item->getPathname()), '/');
            foreach ($preservePaths as $preserve) { if ($rel === $preserve || str_starts_with($rel, rtrim($preserve,'/').'/')) { continue 2; } }
            $dest = $target.'/'.$rel;
            if ($item->isDir()) { if (!is_dir($dest)) { @mkdir($dest, 0770, true); } continue; }
            @mkdir(dirname($dest), 0770, true);
            copy($item->getPathname(), $dest);
        }
    }
}
