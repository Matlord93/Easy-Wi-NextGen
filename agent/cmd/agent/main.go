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
	"strings"
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

	run(ctx, client, cfg, *configPath)
}

func run(ctx context.Context, client *api.Client, cfg config.Config, configPath string) {
	heartbeatTicker := time.NewTicker(cfg.HeartbeatInterval)
	pollTicker := time.NewTicker(cfg.PollInterval)
	defer heartbeatTicker.Stop()
	defer pollTicker.Stop()

	maxConcurrency := resolveMaxConcurrency(cfg.MaxConcurrency, 0)
	roles := collectRoles()
	metadata := collectMetadata(cfg)
	globalJournalStreams = newJournalStreamManager(cfg.MaxJournalStreams, cfg.StreamTTL)
	runner := newJobRunner(maxConcurrency, cfg.MaxJournalStreams)
	globalInstanceLocks = runner.locks
	lastCredentialRefresh := time.Time{}
	credentialRefreshCooldown := 2 * time.Minute

	startServiceServer(ctx, cfg)

	if err := client.SendHeartbeat(ctx, collectStats(cfg.Version, roles), roles, metadata, "online"); err != nil {
		log.Printf("heartbeat failed: %v", err)
		if tryRefreshAgentCredentials(ctx, client, &cfg, configPath, err, &lastCredentialRefresh, credentialRefreshCooldown) {
			log.Printf("agent credentials refreshed; retrying heartbeat")
			if retryErr := client.SendHeartbeat(ctx, collectStats(cfg.Version, roles), roles, metadata, "online"); retryErr != nil {
				log.Printf("heartbeat retry failed: %v", retryErr)
			}
		}
	}

	for {
		select {
		case <-ctx.Done():
			return
		case <-heartbeatTicker.C:
			roles = collectRoles()
			metadata = collectMetadata(cfg)
			if err := client.SendHeartbeat(ctx, collectStats(cfg.Version, roles), roles, metadata, "online"); err != nil {
				log.Printf("heartbeat failed: %v", err)
				if tryRefreshAgentCredentials(ctx, client, &cfg, configPath, err, &lastCredentialRefresh, credentialRefreshCooldown) {
					log.Printf("agent credentials refreshed; heartbeat will use the new secret")
				}
			}
		case <-pollTicker.C:
			jobsList, reportedConcurrency, err := client.PollJobs(ctx)
			if err != nil {
				log.Printf("poll jobs failed: %v", err)
				if tryRefreshAgentCredentials(ctx, client, &cfg, configPath, err, &lastCredentialRefresh, credentialRefreshCooldown) {
					log.Printf("agent credentials refreshed after poll auth failure")
				}
				continue
			}
			maxConcurrency = resolveMaxConcurrency(maxConcurrency, reportedConcurrency)
			logSender := newApiJobLogSender(client)
			for _, job := range jobsList {
				jobCopy := job
				instanceLock, lockMode, isStream := resolveJobScheduling(jobCopy)
				runner.Submit(jobTask{
					job:          jobCopy,
					instanceLock: instanceLock,
					lockMode:     lockMode,
					isStream:     isStream,
					handler: func(job jobs.Job) {
						if err := client.StartJob(ctx, job.ID); err != nil {
							log.Printf("start job failed: %v", err)
							return
						}
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
					},
				})
			}

			orchestratorJobs, reportedAgentConcurrency, err := client.PollAgentJobs(ctx, cfg.AgentID, maxConcurrency)
			if err != nil {
				log.Printf("poll orchestrator jobs failed: %v", err)
				if tryRefreshAgentCredentials(ctx, client, &cfg, configPath, err, &lastCredentialRefresh, credentialRefreshCooldown) {
					log.Printf("agent credentials refreshed after orchestrator poll auth failure")
				}
				continue
			}
			maxConcurrency = resolveMaxConcurrency(cfg.MaxConcurrency, reportedAgentConcurrency)
			runner.SetLimit(maxConcurrency)
			for _, job := range orchestratorJobs {
				jobCopy := job
				runner.Submit(jobTask{
					job:      jobCopy,
					lockMode: jobLockNone,
					handler: func(job jobs.Job) {
						if err := client.StartAgentJob(ctx, cfg.AgentID, job.ID); err != nil {
							log.Printf("start orchestrator job failed: %v", err)
							return
						}
						result := handleOrchestratorJob(job)
						if err := client.FinishAgentJob(ctx, cfg.AgentID, job.ID, result.status, result.logText, result.errorText, result.resultPayload); err != nil {
							log.Printf("finish orchestrator job failed: %v", err)
						}
					},
				})
			}
		}
	}
}

func tryRefreshAgentCredentials(ctx context.Context, client *api.Client, cfg *config.Config, configPath string, requestErr error, lastRefresh *time.Time, cooldown time.Duration) bool {
	if !isAuthFailure(requestErr) {
		return false
	}
	if strings.TrimSpace(cfg.BootstrapToken) == "" {
		log.Printf("agent auth failed, but bootstrap_token is not configured; cannot auto-refresh credentials")
		return false
	}
	if !lastRefresh.IsZero() && time.Since(*lastRefresh) < cooldown {
		return false
	}

	secret, err := client.RefreshSecretWithBootstrap(ctx, cfg.BootstrapToken, runtime.GOOS)
	*lastRefresh = time.Now()
	if err != nil {
		log.Printf("auto-refresh agent credentials failed: %v", err)
		return false
	}

	client.Secret = secret
	cfg.Secret = secret
	if err := config.UpdateSecret(configPath, secret); err != nil {
		log.Printf("warning: refreshed secret but failed to persist to config: %v", err)
	} else {
		log.Printf("agent secret refreshed and persisted to config")
	}

	return true
}

func isAuthFailure(err error) bool {
	if err == nil {
		return false
	}
	msg := strings.ToLower(err.Error())
	return strings.Contains(msg, "api error 401") || strings.Contains(msg, "unauthorized")
}

func resolveMaxConcurrency(configured int, reported int) int {
	if configured < 1 {
		configured = 1
	}
	if reported <= 0 {
		return configured
	}
	if reported < configured {
		return reported
	}
	return configured
}

type jobLockMode int

const (
	jobLockNone jobLockMode = iota
	jobLockRead
	jobLockWrite
)

type jobTask struct {
	job          jobs.Job
	instanceLock string
	lockMode     jobLockMode
	isStream     bool
	handler      func(jobs.Job)
}

type jobRunner struct {
	tasks         chan jobTask
	stopWorker    chan struct{}
	locks         *instanceLockManager
	streamLimiter chan struct{}
	maxStreamJobs int
	mu            sync.Mutex
	limit         int
}

func newJobRunner(limit int, maxStreamJobs int) *jobRunner {
	if maxStreamJobs < 1 {
		maxStreamJobs = 1
	}
	runner := &jobRunner{
		tasks:         make(chan jobTask, 200),
		stopWorker:    make(chan struct{}, 200),
		locks:         newInstanceLockManager(),
		streamLimiter: make(chan struct{}, maxStreamJobs),
		maxStreamJobs: maxStreamJobs,
	}
	runner.SetLimit(limit)
	return runner
}

func (r *jobRunner) SetLimit(limit int) {
	if limit < 1 {
		limit = 1
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	if limit == r.limit {
		return
	}
	if limit > r.limit {
		for i := r.limit; i < limit; i++ {
			go r.worker()
		}
	} else {
		for i := limit; i < r.limit; i++ {
			r.stopWorker <- struct{}{}
		}
	}
	r.limit = limit
}

func (r *jobRunner) Submit(task jobTask) {
	if task.handler == nil {
		return
	}
	r.tasks <- task
}

func (r *jobRunner) worker() {
	for {
		select {
		case task := <-r.tasks:
			r.execute(task)
		case <-r.stopWorker:
			return
		}
	}
}

func (r *jobRunner) execute(task jobTask) {
	run := func() {
		task.handler(task.job)
	}
	if task.isStream {
		r.streamLimiter <- struct{}{}
		defer func() { <-r.streamLimiter }()
	}
	switch task.lockMode {
	case jobLockRead:
		r.locks.WithReadLock(task.instanceLock, run)
	case jobLockWrite:
		r.locks.WithWriteLock(task.instanceLock, run)
	default:
		run()
	}
}

type instanceLockManager struct {
	mu    sync.Mutex
	locks map[string]*instanceRWLock
}

type instanceRWLock struct {
	mu             sync.Mutex
	cond           *sync.Cond
	activeReaders  int
	activeWriter   bool
	pendingWriters int
}

func newInstanceLockManager() *instanceLockManager {
	return &instanceLockManager{locks: map[string]*instanceRWLock{}}
}

func newInstanceRWLock() *instanceRWLock {
	lock := &instanceRWLock{}
	lock.cond = sync.NewCond(&lock.mu)
	return lock
}

func (m *instanceLockManager) getLock(key string) *instanceRWLock {
	m.mu.Lock()
	defer m.mu.Unlock()
	lock, ok := m.locks[key]
	if !ok {
		lock = newInstanceRWLock()
		m.locks[key] = lock
	}
	return lock
}

func (m *instanceLockManager) WithReadLock(key string, fn func()) {
	if key == "" {
		fn()
		return
	}
	lock := m.getLock(key)
	lock.mu.Lock()
	for lock.activeWriter || lock.pendingWriters > 0 {
		lock.cond.Wait()
	}
	lock.activeReaders++
	lock.mu.Unlock()

	defer func() {
		lock.mu.Lock()
		lock.activeReaders--
		if lock.activeReaders == 0 {
			lock.cond.Broadcast()
		}
		lock.mu.Unlock()
	}()
	fn()
}

func (m *instanceLockManager) WithWriteLock(key string, fn func()) {
	if key == "" {
		fn()
		return
	}
	lock := m.getLock(key)
	lock.mu.Lock()
	lock.pendingWriters++
	for lock.activeWriter || lock.activeReaders > 0 {
		lock.cond.Wait()
	}
	lock.pendingWriters--
	lock.activeWriter = true
	lock.mu.Unlock()

	defer func() {
		lock.mu.Lock()
		lock.activeWriter = false
		lock.cond.Broadcast()
		lock.mu.Unlock()
	}()
	fn()
}

func resolveJobScheduling(job jobs.Job) (instanceLock string, lockMode jobLockMode, isStream bool) {
	instanceID := ""
	if job.Payload != nil {
		if value, ok := job.Payload["instance_id"]; ok {
			instanceID = strings.TrimSpace(payloadString(value))
		}
	}
	if instanceID != "" {
		instanceLock = "instance:" + instanceID
	}

	jobType, _ := normalizeJobType(job.Type)
	if strings.HasPrefix(jobType, "instance.") {
		switch jobType {
		case "instance.start", "instance.stop", "instance.restart", "instance.create", "instance.delete", "instance.config.apply", "instance.reinstall", "instance.backup.restore", "instance.files.write", "instance.files.delete", "instance.files.mkdir":
			return instanceLock, jobLockWrite, false
		case "instance.logs.tail":
			return instanceLock, jobLockRead, true
		default:
			return instanceLock, jobLockRead, false
		}
	}

	return "", jobLockNone, false
}

var globalJournalStreams = newJournalStreamManager(4, 75*time.Second)
var globalInstanceLocks = newInstanceLockManager()

var jobTypeAliases = map[string]string{
	"database.rotate_password": "database.password.rotate",
	"instance.files.listing":   "instance.files.list",
	"instance.files.download":  "instance.files.read",
	"instance.files.upload":    "instance.files.write",
	"webspace.files.listing":   "webspace.files.list",
	"webspace.files.download":  "webspace.files.read",
	"webspace.files.upload":    "webspace.files.write",
}

func normalizeJobType(jobType string) (string, bool) {
	canonical, ok := jobTypeAliases[jobType]
	if !ok {
		return jobType, false
	}
	return canonical, true
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
	jobType, aliased := normalizeJobType(job.Type)
	if aliased {
		log.Printf("[DEPRECATION] alias_hit legacy_job_type=%q canonical_job_type=%q", job.Type, jobType)
	}
	if err := ensureJobSupportedByPlatform(jobType); err != nil {
		return failureResult(job.ID, err)
	}

	switch jobType {
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
	case "webspace.update":
		return handleWebspaceUpdate(job)
	case "webspace.apply":
		return handleWebspaceApply(job)
	case "webspace.provision":
		return handleWebspaceCreate(job)
	case "webspace.backup":
		return handleWebspaceBackup(job)
	case "webspace.restore":
		return handleWebspaceRestore(job)
	case "webspace.logs.tail":
		return handleWebspaceLogsTail(job)
	case "webspace.cron.update":
		return handleWebspaceCronUpdate(job)
	case "webspace.git.deploy":
		return handleWebspaceGitDeploy(job)
	case "webspace.composer.install":
		return handleWebspaceComposerInstall(job)
	case "domain.add":
		return handleDomainAdd(job)
	case "webspace.domain.apply":
		return handleWebspaceDomainApply(job)
	case "domain.update":
		return handleDomainAdd(job)
	case "domain.ssl.issue":
		return handleDomainSSLIssue(job)
	case "roundcube.install":
		return handleRoundcubeInstall(job)
	case "mail.domain.create":
		return handleMailDomainCreate(job)
	case "database.create":
		return handleDatabaseCreate(job)
	case "database.password.reset":
		return handleDatabasePasswordReset(job)
	case "database.password.rotate":
		return handleDatabasePasswordRotate(job)
	case "database.user.create":
		return handleDatabaseUserCreate(job)
	case "database.grant.apply":
		return handleDatabaseGrantApply(job)
	case "database.delete":
		return handleDatabaseDelete(job)
	case "voice.probe":
		return handleVoiceProbe(job)
	case "voice.action.start":
		return handleVoiceAction(job, "start")
	case "voice.action.stop":
		return handleVoiceAction(job, "stop")
	case "voice.action.restart":
		return handleVoiceAction(job, "restart")
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
	case "mailbox.delete":
		return handleMailboxDelete(job)
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
	case "instance.delete":
		return handleInstanceDelete(job)
	case "instance.logs.tail":
		return handleInstanceLogsTail(job, logSender)
	case "instance.console.command":
		return handleInstanceConsoleCommand(job, logSender)
	case "instance.reinstall":
		return handleInstanceReinstall(job, logSender)
	case "instance.backup.create":
		return handleInstanceBackupCreate(job)
	case "instance.backup.restore":
		return handleInstanceBackupRestore(job)
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
	case "instance.files.read":
		return handleInstanceFileRead(job)
	case "instance.files.write":
		return handleInstanceFileWrite(job)
	case "instance.files.delete":
		return handleInstanceFileDelete(job)
	case "instance.files.mkdir":
		return handleInstanceFileMkdir(job)
	case "instance.sftp.credentials.reset":
		return handleInstanceSftpCredentialsReset(job)
	case "instance.config.apply":
		return handleInstanceConfigApply(job)
	case "core.ssh.policy.apply":
		return handleCoreSshPolicyApply(job)
	case "instance.query.check":
		return handleInstanceQueryCheck(job)
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
	case "webspace.files.read":
		return handleWebspaceFileRead(job)
	case "webspace.files.write":
		return handleWebspaceFileWrite(job)
	case "webspace.files.delete":
		return handleWebspaceFileDelete(job)
	case "webspace.delete":
		return handleWebspaceDelete(job)
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
	case "fail2ban.policy.apply":
		return handleFail2banPolicyApply(job)
	case "fail2ban.status.check":
		return handleFail2banStatusCheck(job)
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
