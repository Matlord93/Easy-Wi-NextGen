package main

import (
	"context"
	"flag"
	"log"
	"os"
	"os/signal"
	"runtime"
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

	if err := client.SendHeartbeat(ctx, collectStats(cfg.Version)); err != nil {
		log.Printf("heartbeat failed: %v", err)
	}

	for {
		select {
		case <-ctx.Done():
			return
		case <-heartbeatTicker.C:
			if err := client.SendHeartbeat(ctx, collectStats(cfg.Version)); err != nil {
				log.Printf("heartbeat failed: %v", err)
			}
		case <-pollTicker.C:
			jobsList, err := client.PollJobs(ctx)
			if err != nil {
				log.Printf("poll jobs failed: %v", err)
				continue
			}
			for _, job := range jobsList {
				result, afterSubmit := handleJob(job)
				if err := client.SubmitJobResult(ctx, result); err != nil {
					log.Printf("submit job result failed: %v", err)
					continue
				}
				if afterSubmit != nil {
					if err := afterSubmit(); err != nil {
						log.Printf("post-submit job action failed: %v", err)
					}
				}
			}
		}
	}
}

func collectStats(version string) map[string]any {
	return map[string]any{
		"version":    version,
		"go_version": runtime.Version(),
		"os":         runtime.GOOS,
		"arch":       runtime.GOARCH,
		"metrics":    metrics.Collect(),
	}
}

func handleJob(job jobs.Job) (jobs.Result, func() error) {
	switch job.Type {
	case "agent.update":
		return handleAgentUpdate(job)
	case "role.ensure_base":
		return handleRoleEnsureBase(job)
	case "webspace.create":
		return handleWebspaceCreate(job)
	case "domain.add":
		return handleDomainAdd(job)
	case "domain.ssl.issue":
		return handleDomainSSLIssue(job)
	case "mail.domain.create":
		return handleMailDomainCreate(job)
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
		return handleInstanceStart(job)
	case "instance.stop":
		return handleInstanceStop(job)
	case "instance.restart":
		return handleInstanceRestart(job)
	case "instance.reinstall":
		return handleInstanceReinstall(job)
	case "sniper.install":
		return handleSniperInstall(job)
	case "sniper.update":
		return handleSniperUpdate(job)
	case "webspace.files.list":
		return handleWebspaceFilesList(job)
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
	case "ts3.create":
		return handleTs3Create(job)
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
	case "gdpr.anonymize_user":
		return handleGdprAnonymizeUser(job)
	default:
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "job received; executor not configured"},
			Completed: time.Now().UTC(),
		}, nil
	}
}

func handleAgentUpdate(job jobs.Job) (jobs.Result, func() error) {
	downloadURL := payloadValue(job.Payload, "download_url", "artifact_url", "url")
	checksumsURL := payloadValue(job.Payload, "checksums_url", "checksum_url", "checksums")
	assetName := payloadValue(job.Payload, "asset_name")

	if downloadURL == "" || checksumsURL == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing download_url or checksums_url"},
			Completed: time.Now().UTC(),
		}, nil
	}

	binaryPath, err := system.ApplyUpdateFromChecksums(context.Background(), system.UpdateFromChecksumsOptions{
		DownloadURL:  downloadURL,
		ChecksumsURL: checksumsURL,
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
		return system.RestartOrExit(binaryPath)
	}
}

func payloadValue(payload map[string]string, keys ...string) string {
	for _, key := range keys {
		if value := payload[key]; value != "" {
			return value
		}
	}
	return ""
}
