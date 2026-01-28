package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"runtime"
	"sync"
	"syscall"
	"time"

	"easywi/agent/internal/api"
	"easywi/agent/internal/config"
	"easywi/agent/internal/jobs"
	"easywi/agent/internal/metrics"
	"easywi/agent/internal/system"
)

func main() {
	configPath := flag.String("config", "", "path to agent.conf")
	selfUpdate := flag.Bool("self-update", false, "perform self-update and restart")
	flag.Parse()

	cfg, err := config.Load(*configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	if *selfUpdate {
		if err := system.SelfUpdate(context.Background(), system.UpdateOptions{DownloadURL: cfg.UpdateURL, SHA256: cfg.UpdateSHA256}); err != nil {
			log.Fatalf("self update failed: %v", err)
		}
		return
	}

	client, err := api.NewClient(cfg.APIURL, cfg.AgentID, cfg.Secret, cfg.Version)
	if err != nil {
		log.Fatalf("init api client: %v", err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	run(ctx, client, cfg)
}

func run(ctx context.Context, client *api.Client, cfg config.Config) {
	heartbeatTicker := time.NewTicker(cfg.HeartbeatInterval)
	pollTicker := time.NewTicker(cfg.PollInterval)
	defer heartbeatTicker.Stop()
	defer pollTicker.Stop()

	maxConcurrency := 1
	roles := collectRoles()
	metadata := collectMetadata()

	if err := client.SendHeartbeat(ctx, collectStats(cfg.Version, roles), roles, metadata, "online"); err != nil {
		log.Printf("heartbeat failed: %v", err)
	}

	for {
		select {
		case <-ctx.Done():
			return
		case <-heartbeatTicker.C:
			roles = collectRoles()
			metadata = collectMetadata()
			if err := client.SendHeartbeat(ctx, collectStats(cfg.Version, roles), roles, metadata, "online"); err != nil {
				log.Printf("heartbeat failed: %v", err)
			}
		case <-pollTicker.C:
			jobsList, reportedConcurrency, err := client.PollJobs(ctx)
			if err != nil {
				log.Printf("poll jobs failed: %v", err)
				continue
			}
			maxConcurrency = resolveMaxConcurrency(maxConcurrency, reportedConcurrency)
			logSender := newApiJobLogSender(client)
			runJobsConcurrently(maxConcurrency, jobsList, func(job jobs.Job) {
				result, afterSubmit := handleJob(job, logSender)
				if err := client.SubmitJobResult(ctx, result); err != nil {
					log.Printf("submit job result failed: %v", err)
					return
				}
				if afterSubmit != nil {
					if err := afterSubmit(); err != nil {
						log.Printf("post-submit job action failed: %v", err)
					}
				}
			})

			orchestratorJobs, reportedAgentConcurrency, err := client.PollAgentJobs(ctx, cfg.AgentID, maxConcurrency)
			if err != nil {
				log.Printf("poll orchestrator jobs failed: %v", err)
				continue
			}
			maxConcurrency = resolveMaxConcurrency(maxConcurrency, reportedAgentConcurrency)
			runJobsConcurrently(maxConcurrency, orchestratorJobs, func(job jobs.Job) {
				if err := client.StartAgentJob(ctx, cfg.AgentID, job.ID); err != nil {
					log.Printf("start orchestrator job failed: %v", err)
					return
				}
				result := handleOrchestratorJob(job)
				if err := client.FinishAgentJob(ctx, cfg.AgentID, job.ID, result.status, result.logText, result.errorText, result.resultPayload); err != nil {
					log.Printf("finish orchestrator job failed: %v", err)
				}
			})
		}
	}
}

func resolveMaxConcurrency(current int, reported int) int {
	if reported <= 0 {
		if current > 0 {
			return current
		}
		return 1
	}
	if reported < 1 {
		return 1
	}
	return reported
}

func runJobsConcurrently(limit int, jobsList []jobs.Job, handler func(jobs.Job)) {
	if limit <= 0 {
		limit = 1
	}
	if len(jobsList) == 0 {
		return
	}

	semaphore := make(chan struct{}, limit)
	var wg sync.WaitGroup

	for _, job := range jobsList {
		wg.Add(1)
		go func(job jobs.Job) {
			defer wg.Done()
			semaphore <- struct{}{}
			defer func() { <-semaphore }()
			handler(job)
		}(job)
	}

	wg.Wait()
}

func collectStats(version string, roles []string) map[string]any {
	return map[string]any{
		"version":         version,
		"go_version":      runtime.Version(),
		"os":              runtime.GOOS,
		"arch":            runtime.GOARCH,
		"roles":           roles,
		"os_provider":     detectOSProvider(),
		"services":        collectServiceStatus(roles),
		"reboot_required": isRebootRequired(),
		"metrics":         metrics.Collect(),
	}
}

func handleJob(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" && !isWindowsSafeJob(job.Type) {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "job type is not permitted on Windows agents"},
			Completed: time.Now().UTC(),
		}, nil
	}

	switch job.Type {
	case "agent.update":
		return handleAgentUpdate(job)
	case "agent.self_update":
		return handleAgentUpdate(job)
	case "agent.diagnostics":
		return handleAgentDiagnostics(job)
	case "os.update":
		return handleOSUpdate(job)
	case "os.reboot":
		return handleOSReboot(job)
	case "server.update.check":
		return handleServerUpdateCheck(job)
	case "server.update.run":
		return handleServerUpdateRun(job)
	case "role.ensure_base":
		return handleRoleEnsureBase(job)
	case "security.ensure_base":
		return handleSecurityEnsureBase(job)
	case "game.ensure_base":
		return handleGameEnsureBase(job)
	case "web.ensure_base":
		return handleWebEnsureBase(job)
	case "mail.ensure_base":
		return handleMailEnsureBase(job)
	case "dns.ensure_base":
		return handleDnsEnsureBase(job)
	case "db.ensure_base":
		return handleDbEnsureBase(job)
	case "webspace.create":
		return handleWebspaceCreate(job)
	case "domain.add":
		return handleDomainAdd(job)
	case "domain.ssl.issue":
		return handleDomainSSLIssue(job)
	case "mail.domain.create":
		return handleMailDomainCreate(job)
	case "database.create":
		return handleDatabaseCreate(job)
	case "database.password.reset":
		return handleDatabasePasswordReset(job)
	case "mail.alias.create":
		return handleMailAliasCreate(job)
	case "mail.alias.update":
		return handleMailAliasUpdate(job)
	case "mail.alias.delete":
		return handleMailAliasDelete(job)
	case "mail.alias.enable":
		return handleMailAliasEnable(job)
	case "mail.alias.disable":
		return handleMailAliasDisable(job)
	case "mailbox.create":
		return handleMailboxCreate(job)
	case "mailbox.password.reset":
		return handleMailboxPasswordReset(job)
	case "mailbox.quota.update":
		return handleMailboxQuotaUpdate(job)
	case "mailbox.enable":
		return handleMailboxEnable(job)
	case "mailbox.disable":
		return handleMailboxDisable(job)
	case "dns.zone.create":
		return handleDNSZoneCreate(job)
	case "dns.record.create":
		return handleDNSRecordCreate(job)
	case "dns.record.update":
		return handleDNSRecordUpdate(job)
	case "dns.record.delete":
		return handleDNSRecordDelete(job)
	case "mariadb.db.create":
		return handleMariaDBDatabaseCreate(job)
	case "mariadb.user.create":
		return handleMariaDBUserCreate(job)
	case "mariadb.grant.apply":
		return handleMariaDBGrantApply(job)
	case "postgres.db.create":
		return handlePostgresDatabaseCreate(job)
	case "postgres.role.create":
		return handlePostgresRoleCreate(job)
	case "postgres.grant.apply":
		return handlePostgresGrantApply(job)
	case "instance.create":
		return handleInstanceCreate(job)
	case "instance.start":
		return handleInstanceStart(job, logSender)
	case "instance.stop":
		return handleInstanceStop(job, logSender)
	case "instance.restart":
		return handleInstanceRestart(job, logSender)
	case "instance.logs.tail":
		return handleInstanceLogsTail(job, logSender)
	case "instance.console.command":
		return handleInstanceConsoleCommand(job, logSender)
	case "instance.reinstall":
		return handleInstanceReinstall(job, logSender)
	case "instance.addon.install":
		return handleInstanceAddonInstall(job)
	case "instance.addon.update":
		return handleInstanceAddonUpdate(job)
	case "instance.addon.remove":
		return handleInstanceAddonRemove(job)
	case "instance.disk.scan":
		return handleInstanceDiskScan(job)
	case "instance.disk.top":
		return handleInstanceDiskTop(job)
	case "instance.files.list":
		return handleInstanceFilesList(job)
	case "instance.files.listing":
		return handleInstanceFilesList(job)
	case "instance.files.read":
		return handleInstanceFileRead(job)
	case "instance.files.download":
		return handleInstanceFileRead(job)
	case "instance.files.write":
		return handleInstanceFileWrite(job)
	case "instance.files.upload":
		return handleInstanceFileWrite(job)
	case "instance.files.delete":
		return handleInstanceFileDelete(job)
	case "instance.files.mkdir":
		return handleInstanceFileMkdir(job)
	case "instance.sftp.credentials.reset":
		return handleInstanceSftpCredentialsReset(job)
	case "instance.sftp.access.enable":
		return handleInstanceSftpAccessEnable(job)
	case "instance.sftp.access.reset_password":
		return handleInstanceSftpAccessResetPassword(job)
	case "instance.sftp.access.keys":
		return handleInstanceSftpAccessKeys(job)
	case "instance.sftp.access.disable":
		return handleInstanceSftpAccessDisable(job)
	case "sniper.install":
		return handleSniperInstall(job, logSender)
	case "sniper.update":
		return handleSniperUpdate(job, logSender)
	case "node.disk.stat":
		return handleNodeDiskStat(job)
	case "webspace.files.list":
		return handleWebspaceFilesList(job)
	case "webspace.files.listing":
		return handleWebspaceFilesList(job)
	case "webspace.files.read":
		return handleWebspaceFileRead(job)
	case "webspace.files.download":
		return handleWebspaceFileRead(job)
	case "webspace.files.write":
		return handleWebspaceFileWrite(job)
	case "webspace.files.upload":
		return handleWebspaceFileWrite(job)
	case "webspace.files.delete":
		return handleWebspaceFileDelete(job)
	case "webspace.files.mkdir":
		return handleWebspaceFileMkdir(job)
	case "windows.service.start":
		return handleWindowsServiceStart(job)
	case "windows.service.stop":
		return handleWindowsServiceStop(job)
	case "windows.service.restart":
		return handleWindowsServiceRestart(job)
	case "firewall.open_ports":
		return handleFirewallOpen(job.ID, job.Payload)
	case "firewall.close_ports":
		return handleFirewallClose(job.ID, job.Payload)
	case "ddos.policy.apply":
		return handleDdosPolicyApply(job)
	case "ddos.status.check":
		return handleDdosStatusCheck(job)
	case "ts3.create":
		return handleTs3Create(job, logSender)
	case "ts3.start":
		return handleTs3Start(job)
	case "ts3.stop":
		return handleTs3Stop(job)
	case "ts3.restart":
		return handleTs3Restart(job)
	case "ts3.update":
		return handleTs3Update(job)
	case "ts3.backup":
		return handleTs3Backup(job)
	case "ts3.restore":
		return handleTs3Restore(job)
	case "ts3.token.reset":
		return handleTs3TokenReset(job)
	case "ts3.slots.set":
		return handleTs3SlotsSet(job)
	case "ts3.logs.export":
		return handleTs3LogsExport(job)
	case "server.reboot.check_required":
		return handleServerRebootCheckRequired(job)
	case "server.reboot.run":
		return handleServerRebootRun(job)
	case "gdpr.anonymize_user":
		return handleGdprAnonymizeUser(job)
	case "server.status.check":
		return handleServerStatusCheck(job)
	default:
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "job received; executor not configured"},
			Completed: time.Now().UTC(),
		}, nil
	}
}

func isWindowsSafeJob(jobType string) bool {
	switch jobType {
	case "agent.update",
		"agent.self_update",
		"agent.diagnostics",
		"role.ensure_base",
		"security.ensure_base",
		"web.ensure_base",
		"ddos.policy.apply",
		"ddos.status.check",
		"windows.service.start",
		"windows.service.stop",
		"windows.service.restart",
		"server.reboot.check_required",
		"server.reboot.run",
		"server.status.check",
		"instance.sftp.credentials.reset":
		return true
	default:
		return false
	}
}

func handleAgentUpdate(job jobs.Job) (jobs.Result, func() error) {
	downloadURL := payloadValue(job.Payload, "download_url", "artifact_url", "url")
	checksumsURL := payloadValue(job.Payload, "checksums_url", "checksum_url", "checksums")
	signatureURL := payloadValue(job.Payload, "signature_url", "checksums_signature_url", "checksum_signature_url")
	assetName := payloadValue(job.Payload, "asset_name")

	if downloadURL == "" || checksumsURL == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing download_url or checksums_url"},
			Completed: time.Now().UTC(),
		}, nil
	}

	updatePlan, err := system.ApplyUpdateFromChecksums(context.Background(), system.UpdateFromChecksumsOptions{
		DownloadURL:  downloadURL,
		ChecksumsURL: checksumsURL,
		SignatureURL: signatureURL,
		AssetName:    assetName,
	})
	if err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error()},
			Completed: time.Now().UTC(),
		}, nil
	}

	result := jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    map[string]string{"message": "update applied; restarting agent"},
		Completed: time.Now().UTC(),
	}

	return result, func() error {
		if runtime.GOOS == "windows" && updatePlan.UpdatePath != "" {
			return system.ApplyWindowsUpdate(updatePlan)
		}
		return system.RestartOrExit(updatePlan.BinaryPath)
	}
}

func payloadValue(payload map[string]any, keys ...string) string {
	for _, key := range keys {
		if value, ok := payload[key]; ok {
			if stringValue := payloadString(value); stringValue != "" {
				return stringValue
			}
		}
	}
	return ""
}

func payloadString(value any) string {
	switch typed := value.(type) {
	case nil:
		return ""
	case string:
		return typed
	case []byte:
		return string(typed)
	case fmt.Stringer:
		return typed.String()
	case float64, float32, int, int64, int32, uint, uint64, uint32, bool:
		return fmt.Sprint(typed)
	default:
		encoded, err := json.Marshal(typed)
		if err != nil {
			return ""
		}
		return string(encoded)
	}
}
