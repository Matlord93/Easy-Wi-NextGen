package main

import (
	"bufio"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
	"golang.org/x/crypto/ssh"
)

var queryPromptRegex = regexp.MustCompile(`^[A-Za-z0-9._-]+>\s*$`)

func handleTs3VirtualCreate(job jobs.Job) orchestratorResult {
	client, err := newTs3QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	name := payloadValue(job.Payload, "name")
	if strings.TrimSpace(name) == "" {
		return orchestratorResult{status: "failed", errorText: "missing virtual server name"}
	}

	params := payloadMap(job.Payload, "params")
	voicePort := payloadValue(params, "voice_port")
	filePort := payloadValue(params, "filetransfer_port")
	maxClients := payloadValue(params, "slots", "max_clients")

	args := []string{fmt.Sprintf("virtualserver_name=%s", escapeTs3Query(name))}
	if voicePort != "" {
		args = append(args, fmt.Sprintf("virtualserver_port=%s", voicePort))
	}
	if filePort != "" {
		args = append(args, fmt.Sprintf("virtualserver_filetransfer_port=%s", filePort))
	}
	if maxClients != "" {
		args = append(args, fmt.Sprintf("virtualserver_maxclients=%s", maxClients))
	}
	response, err := client.command("servercreate " + strings.Join(args, " "))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	sid := response["sid"]
	token := response["token"]
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "servercreate did not return sid"}
	}

	payload := map[string]any{
		"sid": sid,
	}
	if voicePort != "" {
		payload["voice_port"] = voicePort
	}
	if filePort != "" {
		payload["filetransfer_port"] = filePort
	}
	if token != "" {
		payload["token"] = token
	}

	return orchestratorResult{
		status:        "success",
		resultPayload: payload,
	}
}

func handleTs6VirtualCreate(job jobs.Job) orchestratorResult {
	client, err := newTs6QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	name := payloadValue(job.Payload, "name")
	if strings.TrimSpace(name) == "" {
		return orchestratorResult{status: "failed", errorText: "missing virtual server name"}
	}

	existingServers, err := listVirtualServers(client)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	existingSummary := formatVirtualServerSummary(existingServers)
	if findVirtualServerByName(existingServers, name) {
		return orchestratorResult{
			status:    "failed",
			errorText: fmt.Sprintf("virtual server %q already exists", name),
			logText:   existingSummary,
		}
	}

	params := payloadMap(job.Payload, "params")
	voicePort := payloadValue(params, "voice_port")
	filePort := payloadValue(params, "filetransfer_port")
	maxClients := payloadValue(params, "slots", "max_clients")

	args := []string{fmt.Sprintf("virtualserver_name=%s", escapeTs3Query(name))}
	if voicePort != "" {
		args = append(args, fmt.Sprintf("virtualserver_port=%s", voicePort))
	}
	if filePort != "" {
		args = append(args, fmt.Sprintf("virtualserver_filetransfer_port=%s", filePort))
	}
	if maxClients != "" {
		args = append(args, fmt.Sprintf("virtualserver_maxclients=%s", maxClients))
	}
	response, err := client.command("servercreate " + strings.Join(args, " "))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	sid := response["sid"]
	token := response["token"]
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "servercreate did not return sid"}
	}

	payload := map[string]any{
		"sid": sid,
	}
	if voicePort != "" {
		payload["voice_port"] = voicePort
	}
	if filePort != "" {
		payload["filetransfer_port"] = filePort
	}
	if token != "" {
		payload["token"] = token
	}

	return orchestratorResult{
		status:        "success",
		logText:       existingSummary,
		resultPayload: payload,
	}
}

func handleTs3VirtualAction(job jobs.Job) orchestratorResult {
	client, err := newTs3QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	if sid == "" || action == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid or action"}
	}

	var command string
	switch action {
	case "start":
		command = fmt.Sprintf("serverstart sid=%s", sid)
	case "stop":
		command = fmt.Sprintf("serverstop sid=%s", sid)
	case "restart":
		if _, err := client.command(fmt.Sprintf("serverstop sid=%s", sid)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		command = fmt.Sprintf("serverstart sid=%s", sid)
	case "delete":
		command = fmt.Sprintf("serverdelete sid=%s", sid)
	default:
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("unsupported action: %s", action)}
	}

	if _, err := client.command(command); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"sid":    sid,
			"action": action,
		},
	}
}

func handleTs6VirtualAction(job jobs.Job) orchestratorResult {
	client, err := newTs6QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	if sid == "" || action == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid or action"}
	}

	var command string
	switch action {
	case "start":
		command = fmt.Sprintf("serverstart sid=%s", sid)
	case "stop":
		command = fmt.Sprintf("serverstop sid=%s", sid)
	case "restart":
		if _, err := client.command(fmt.Sprintf("serverstop sid=%s", sid)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		command = fmt.Sprintf("serverstart sid=%s", sid)
	case "delete":
		command = fmt.Sprintf("serverdelete sid=%s", sid)
	default:
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("unsupported action: %s", action)}
	}

	if _, err := client.command(command); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"sid":    sid,
			"action": action,
		},
	}
}

func handleTs6VirtualList(job jobs.Job) orchestratorResult {
	client, err := newTs6QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	servers, err := listVirtualServers(client)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	normalized := normalizeVirtualServerList(servers)
	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"servers": normalized,
		},
	}
}

func handleTs3VirtualTokenRotate(job jobs.Job) orchestratorResult {
	client, err := newTs3QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}
	serverGroupID := payloadValue(job.Payload, "server_group_id", "sgid")
	if serverGroupID == "" {
		return orchestratorResult{status: "failed", errorText: "missing server_group_id"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	response, err := client.command(fmt.Sprintf("tokenadd tokentype=0 tokenid1=%s tokenid2=0", serverGroupID))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	token := response["token"]
	if token == "" {
		return orchestratorResult{status: "failed", errorText: "token rotate did not return token"}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"token":      token,
			"token_type": fmt.Sprintf("server_group:%s", serverGroupID),
		},
	}
}

func handleTs6VirtualTokenRotate(job jobs.Job) orchestratorResult {
	client, err := newTs6QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}
	serverGroupID := payloadValue(job.Payload, "server_group_id", "sgid")
	if serverGroupID == "" {
		return orchestratorResult{status: "failed", errorText: "missing server_group_id"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	response, err := client.command(fmt.Sprintf("tokenadd tokentype=0 tokenid1=%s tokenid2=0", serverGroupID))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	token := response["token"]
	if token == "" {
		return orchestratorResult{status: "failed", errorText: "token rotate did not return token"}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"token":      token,
			"token_type": fmt.Sprintf("server_group:%s", serverGroupID),
		},
	}
}

func handleTs3ServerGroupList(job jobs.Job) orchestratorResult {
	client, err := newTs3QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	groups, err := listServerGroups(client)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"groups": normalizeServerGroupList(groups),
		},
	}
}

func handleTs6ServerGroupList(job jobs.Job) orchestratorResult {
	client, err := newTs6QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	groups, err := listServerGroups(client)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"groups": normalizeServerGroupList(groups),
		},
	}
}

func handleTs3VirtualSummary(job jobs.Job) orchestratorResult {
	client, err := newTs3QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	response, err := client.command("serverinfo")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"clients_online":    parseOptionalInt(response["virtualserver_clientsonline"]),
			"max_clients":       parseOptionalInt(response["virtualserver_maxclients"]),
			"voice_port":        parseOptionalInt(response["virtualserver_port"]),
			"filetransfer_port": parseOptionalInt(response["virtualserver_filetransfer_port"]),
		},
	}
}

func handleTs6VirtualSummary(job jobs.Job) orchestratorResult {
	client, err := newTs6QueryClient(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	response, err := client.command("serverinfo")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"clients_online":    parseOptionalInt(response["virtualserver_clientsonline"]),
			"max_clients":       parseOptionalInt(response["virtualserver_maxclients"]),
			"voice_port":        parseOptionalInt(response["virtualserver_port"]),
			"filetransfer_port": parseOptionalInt(response["virtualserver_filetransfer_port"]),
		},
	}
}

func handleTs3VirtualBanList(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs3QueryClient, "banlist", "bans")
}

func handleTs6VirtualBanList(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs6QueryClient, "banlist", "bans")
}

func handleTs3VirtualChannelList(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs3QueryClient, "channellist", "channels")
}

func handleTs6VirtualChannelList(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs6QueryClient, "channellist", "channels")
}

func handleTs3VirtualClientList(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs3QueryClient, "clientlist", "clients")
}

func handleTs6VirtualClientList(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs6QueryClient, "clientlist", "clients")
}

func handleTs3VirtualLogView(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs3QueryClient, "logview", "logs")
}

func handleTs6VirtualLogView(job jobs.Job) orchestratorResult {
	return handleTsQueryList(job, newTs6QueryClient, "logview", "logs")
}

func handleTs3VirtualSnapshot(job jobs.Job) orchestratorResult {
	return handleTsSnapshot(job, newTs3QueryClient)
}

func handleTs6VirtualSnapshot(job jobs.Job) orchestratorResult {
	return handleTsSnapshot(job, newTs6QueryClient)
}

func handleTsQueryList(job jobs.Job, builder func(map[string]any) (*ts3QueryClient, error), command, key string) orchestratorResult {
	client, err := builder(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	lines, err := client.commandLines(command)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			key: parseQueryList(lines),
		},
	}
}

func handleTsSnapshot(job jobs.Job, builder func(map[string]any) (*ts3QueryClient, error)) orchestratorResult {
	client, err := builder(job.Payload)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	defer client.close()

	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	response, err := client.command("serversnapshotcreate")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"snapshot": response["snapshot"],
		},
	}
}

func newTs3QueryClient(payload map[string]any) (*ts3QueryClient, error) {
	queryIP := payloadValue(payload, "query_bind_ip", "query_ip")
	if queryIP == "" {
		queryIP = "127.0.0.1"
	}
	queryIP = normalizeQueryConnectIP(queryIP)
	queryPort := payloadValue(payload, "query_port")
	if queryPort == "" {
		queryPort = "10011"
	}
	adminUser := payloadValue(payload, "admin_username")
	if adminUser == "" {
		adminUser = "serveradmin"
	}
	adminPass := payloadValue(payload, "admin_password")
	if adminPass == "" {
		return nil, fmt.Errorf("missing admin_password")
	}
	address := net.JoinHostPort(queryIP, queryPort)

	conn, err := net.DialTimeout("tcp", address, 5*time.Second)
	if err != nil {
		return nil, fmt.Errorf("connect serverquery: %w", err)
	}
	return newTsQueryClientWithConn(conn, adminUser, adminPass)
}

func newTs6QueryClient(payload map[string]any) (*ts3QueryClient, error) {
	protocol := strings.ToLower(strings.TrimSpace(payloadValue(payload, "query_protocol", "query_transport")))
	switch protocol {
	case "ssh":
		return newTs6QueryClientSSH(payload)
	case "", "auto", "tcp":
	default:
		return nil, fmt.Errorf("unsupported query protocol: %s", protocol)
	}

	client, err := newTs6QueryClientSSH(payload)
	if err == nil {
		return client, nil
	}
	if protocol == "ssh" {
		return nil, err
	}

	tcpClient, tcpErr := newTs6QueryClientTCP(payload)
	if tcpErr == nil {
		return tcpClient, nil
	}
	return nil, fmt.Errorf("connect serverquery: ssh failed: %v; tcp fallback failed: %v", err, tcpErr)
}

func newTs6QueryClientTCP(payload map[string]any) (*ts3QueryClient, error) {
	queryIP := payloadValue(payload, "query_bind_ip", "query_ip")
	if queryIP == "" {
		queryIP = "127.0.0.1"
	}
	queryIP = normalizeQueryConnectIP(queryIP)
	queryPort := payloadValue(payload, "query_port", "query_https_port")
	if queryPort == "" {
		queryPort = "10443"
	}
	fallbackPort := payloadValue(payload, "query_http_port")
	if fallbackPort == "" {
		fallbackPort = "10080"
	}
	adminUser := payloadValue(payload, "admin_username")
	if adminUser == "" {
		adminUser = "serveradmin"
	}
	adminPass, err := resolveTs6AdminPassword(payload)
	if err != nil {
		return nil, err
	}
	address := net.JoinHostPort(queryIP, queryPort)

	conn, err := net.DialTimeout("tcp", address, 5*time.Second)
	if err != nil {
		primaryErr := err
		if fallbackPort != "" && fallbackPort != queryPort {
			fallbackAddress := net.JoinHostPort(queryIP, fallbackPort)
			conn, err = net.DialTimeout("tcp", fallbackAddress, 5*time.Second)
			if err != nil {
				return nil, fmt.Errorf("connect serverquery: primary %s failed: %w; fallback %s failed: %v", address, primaryErr, fallbackAddress, err)
			}
		} else {
			return nil, fmt.Errorf("connect serverquery: %w", primaryErr)
		}
	}

	return newTsQueryClientWithConn(conn, adminUser, adminPass)
}

func newTs6QueryClientSSH(payload map[string]any) (*ts3QueryClient, error) {
	queryIP := payloadValue(payload, "query_bind_ip", "query_ip")
	if queryIP == "" {
		queryIP = "127.0.0.1"
	}
	queryIP = normalizeQueryConnectIP(queryIP)
	queryPort := payloadValue(payload, "query_ssh_port")
	if queryPort == "" {
		queryPort = "10022"
	}
	adminUser := payloadValue(payload, "query_ssh_username", "admin_username")
	if adminUser == "" {
		adminUser = "serveradmin"
	}
	adminPass, err := resolveTs6AdminPassword(payload)
	if err != nil {
		return nil, err
	}
	sshPassword := payloadValue(payload, "query_ssh_password")
	if sshPassword == "" {
		sshPassword = adminPass
	}
	address := net.JoinHostPort(queryIP, queryPort)

	sshClient, err := ssh.Dial("tcp", address, &ssh.ClientConfig{
		User:            adminUser,
		Auth:            []ssh.AuthMethod{ssh.Password(sshPassword)},
		HostKeyCallback: ssh.InsecureIgnoreHostKey(),
		Timeout:         5 * time.Second,
	})
	if err != nil {
		return nil, fmt.Errorf("connect serverquery over ssh: %w", err)
	}
	session, err := sshClient.NewSession()
	if err != nil {
		_ = sshClient.Close()
		return nil, fmt.Errorf("open serverquery ssh session: %w", err)
	}
	stdin, err := session.StdinPipe()
	if err != nil {
		_ = session.Close()
		_ = sshClient.Close()
		return nil, fmt.Errorf("open serverquery ssh stdin: %w", err)
	}
	stdout, err := session.StdoutPipe()
	if err != nil {
		_ = session.Close()
		_ = sshClient.Close()
		return nil, fmt.Errorf("open serverquery ssh stdout: %w", err)
	}
	session.Stderr = session.Stdout
	if err := session.RequestPty("xterm", 80, 40, ssh.TerminalModes{ssh.ECHO: 0}); err != nil {
		_ = session.Close()
		_ = sshClient.Close()
		return nil, fmt.Errorf("request serverquery ssh pty: %w", err)
	}
	if err := session.Shell(); err != nil {
		_ = session.Close()
		_ = sshClient.Close()
		return nil, fmt.Errorf("start serverquery ssh shell: %w", err)
	}

	conn := &sshQueryConn{
		stdin:   stdin,
		stdout:  stdout,
		client:  sshClient,
		session: session,
	}

	return newTsQueryClientWithConn(conn, adminUser, adminPass)
}

func resolveTs6AdminPassword(payload map[string]any) (string, error) {
	if adminPass := strings.TrimSpace(payloadValue(payload, "admin_password")); adminPass != "" {
		return adminPass, nil
	}

	configPaths := []string{}
	if configPath := strings.TrimSpace(payloadValue(payload, "ts6_config_path")); configPath != "" {
		configPaths = append(configPaths, configPath)
	}
	if installDir := strings.TrimSpace(payloadValue(payload, "install_dir")); installDir != "" {
		configPaths = append(configPaths, filepath.Join(installDir, "tsserver.yaml"))
	}
	configPaths = append(configPaths,
		"/home/teamspeak6/tsserver.yaml",
		"/opt/teamspeak/ts6/tsserver.yaml",
	)

	for _, configPath := range configPaths {
		if configPath == "" {
			continue
		}
		adminPass, err := readTs6AdminPassword(configPath)
		if err == nil && adminPass != "" {
			return adminPass, nil
		}
	}

	return "", fmt.Errorf("missing admin_password")
}

func readTs6AdminPassword(configPath string) (string, error) {
	content, err := os.ReadFile(configPath)
	if err != nil {
		return "", err
	}
	re := regexp.MustCompile(`(?m)^\s*admin-password:\s*(.+?)\s*$`)
	matches := re.FindStringSubmatch(string(content))
	if len(matches) < 2 {
		return "", fmt.Errorf("admin-password not found in %s", configPath)
	}
	value := strings.TrimSpace(matches[1])
	value = strings.Trim(value, "\"'")
	if value == "" || value == "null" {
		return "", fmt.Errorf("admin-password empty in %s", configPath)
	}
	return value, nil
}

type queryConn interface {
	io.Reader
	io.Writer
	Close() error
}

type sshQueryConn struct {
	stdin   io.WriteCloser
	stdout  io.Reader
	client  *ssh.Client
	session *ssh.Session
}

func (conn *sshQueryConn) Read(p []byte) (int, error) {
	return conn.stdout.Read(p)
}

func (conn *sshQueryConn) Write(p []byte) (int, error) {
	return conn.stdin.Write(p)
}

func (conn *sshQueryConn) Close() error {
	if conn == nil {
		return nil
	}
	if conn.session != nil {
		_ = conn.session.Close()
	}
	if conn.stdin != nil {
		_ = conn.stdin.Close()
	}
	if conn.client != nil {
		_ = conn.client.Close()
	}
	return nil
}

type ts3QueryClient struct {
	conn   queryConn
	reader *bufio.Reader
	writer *bufio.Writer
}

func (client *ts3QueryClient) close() {
	if client == nil {
		return
	}
	_ = client.conn.Close()
}

func newTsQueryClientWithConn(conn queryConn, adminUser, adminPass string) (*ts3QueryClient, error) {
	client := &ts3QueryClient{
		conn:   conn,
		reader: bufio.NewReader(conn),
		writer: bufio.NewWriter(conn),
	}

	if err := client.drainGreeting(); err != nil {
		_ = conn.Close()
		return nil, err
	}
	if _, err := client.command(fmt.Sprintf("login %s %s", escapeTs3Query(adminUser), escapeTs3Query(adminPass))); err != nil {
		_ = conn.Close()
		return nil, err
	}

	return client, nil
}

func normalizeQueryConnectIP(queryIP string) string {
	normalized := strings.TrimSpace(queryIP)
	switch normalized {
	case "", "0.0.0.0", "::":
		return "127.0.0.1"
	default:
		return normalized
	}
}

func (client *ts3QueryClient) command(cmd string) (map[string]string, error) {
	if _, err := client.writer.WriteString(cmd + "\n"); err != nil {
		return nil, err
	}
	if err := client.writer.Flush(); err != nil {
		return nil, err
	}
	lines, err := client.readResponse()
	if err != nil {
		return nil, err
	}
	return parseTs3QueryLines(lines), nil
}

func (client *ts3QueryClient) commandLines(cmd string) ([]string, error) {
	if _, err := client.writer.WriteString(cmd + "\n"); err != nil {
		return nil, err
	}
	if err := client.writer.Flush(); err != nil {
		return nil, err
	}
	return client.readResponse()
}

func (client *ts3QueryClient) drainGreeting() error {
	for {
		line, err := client.reader.ReadString('\n')
		if err != nil {
			return err
		}
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		if isQueryPromptLine(line) {
			return nil
		}
		if strings.HasPrefix(line, "error id=") {
			if !strings.HasPrefix(line, "error id=0") {
				return fmt.Errorf("serverquery error: %s", line)
			}
			return nil
		}
	}
}

func (client *ts3QueryClient) readResponse() ([]string, error) {
	lines := []string{}
	for {
		line, err := client.reader.ReadString('\n')
		if err != nil {
			return nil, err
		}
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		if isQueryPromptLine(line) {
			continue
		}
		if strings.HasPrefix(line, "error id=") {
			if !strings.HasPrefix(line, "error id=0") {
				return nil, fmt.Errorf("serverquery error: %s", line)
			}
			return lines, nil
		}
		lines = append(lines, line)
	}
}

func parseTs3QueryLines(lines []string) map[string]string {
	output := map[string]string{}
	for _, line := range lines {
		for _, part := range strings.Split(line, " ") {
			if part == "" {
				continue
			}
			kv := strings.SplitN(part, "=", 2)
			if len(kv) != 2 {
				continue
			}
			output[kv[0]] = unescapeTs3Query(kv[1])
		}
	}
	return output
}

func parseTs3QueryLine(line string) map[string]string {
	output := map[string]string{}
	for _, part := range strings.Split(line, " ") {
		if part == "" {
			continue
		}
		kv := strings.SplitN(part, "=", 2)
		if len(kv) != 2 {
			continue
		}
		output[kv[0]] = unescapeTs3Query(kv[1])
	}
	return output
}

func isQueryPromptLine(line string) bool {
	if line == "" {
		return false
	}
	if line == "TS3" {
		return true
	}
	return queryPromptRegex.MatchString(line)
}

func escapeTs3Query(value string) string {
	replacer := strings.NewReplacer(
		`\\`, `\\\\`,
		"/", `\/`,
		" ", `\s`,
		"|", `\p`,
		"\a", `\a`,
		"\b", `\b`,
		"\f", `\f`,
		"\n", `\n`,
		"\r", `\r`,
		"\t", `\t`,
	)
	return replacer.Replace(value)
}

func unescapeTs3Query(value string) string {
	replacer := strings.NewReplacer(
		`\\`, `\`,
		`\/`, "/",
		`\s`, " ",
		`\p`, "|",
		`\a`, "\a",
		`\b`, "\b",
		`\f`, "\f",
		`\n`, "\n",
		`\r`, "\r",
		`\t`, "\t",
	)
	return replacer.Replace(value)
}

func payloadMap(payload map[string]any, key string) map[string]any {
	value, ok := payload[key]
	if !ok {
		return map[string]any{}
	}
	if typed, ok := value.(map[string]any); ok {
		return typed
	}
	return map[string]any{}
}

func listVirtualServers(client *ts3QueryClient) ([]map[string]string, error) {
	lines, err := client.commandLines("serverlist")
	if err != nil {
		return nil, err
	}
	servers := []map[string]string{}
	for _, line := range lines {
		if line == "" {
			continue
		}
		for _, chunk := range strings.Split(line, "|") {
			chunk = strings.TrimSpace(chunk)
			if chunk == "" {
				continue
			}
			entry := parseTs3QueryLine(chunk)
			if len(entry) > 0 {
				servers = append(servers, entry)
			}
		}
	}
	return servers, nil
}

func listServerGroups(client *ts3QueryClient) ([]map[string]string, error) {
	lines, err := client.commandLines("servergrouplist")
	if err != nil {
		return nil, err
	}
	groups := []map[string]string{}
	for _, line := range lines {
		if line == "" {
			continue
		}
		for _, chunk := range strings.Split(line, "|") {
			chunk = strings.TrimSpace(chunk)
			if chunk == "" {
				continue
			}
			entry := parseTs3QueryLine(chunk)
			if len(entry) > 0 {
				groups = append(groups, entry)
			}
		}
	}
	return groups, nil
}

func parseQueryList(lines []string) []map[string]string {
	results := []map[string]string{}
	for _, line := range lines {
		if line == "" {
			continue
		}
		for _, chunk := range strings.Split(line, "|") {
			chunk = strings.TrimSpace(chunk)
			if chunk == "" {
				continue
			}
			entry := parseTs3QueryLine(chunk)
			if len(entry) > 0 {
				results = append(results, entry)
			}
		}
	}
	return results
}

func normalizeServerGroupList(groups []map[string]string) []map[string]any {
	results := make([]map[string]any, 0, len(groups))
	for _, group := range groups {
		idValue := resolveVirtualServerValue(group, "sgid", "servergroup_id", "group_id", "id")
		if idValue == "" {
			continue
		}
		name := resolveVirtualServerValue(group, "name", "servergroup_name", "group_name")
		if name == "" {
			name = fmt.Sprintf("Server Group %s", idValue)
		}
		results = append(results, map[string]any{
			"id":   parseOptionalInt(idValue),
			"name": name,
		})
	}
	return results
}

func formatVirtualServerSummary(servers []map[string]string) string {
	if len(servers) == 0 {
		return ""
	}
	entries := make([]string, 0, len(servers))
	for _, server := range servers {
		id := resolveVirtualServerValue(server, "virtualserver_id", "sid", "server_id", "id")
		name := resolveVirtualServerValue(server, "virtualserver_name", "server_name", "name")
		if name == "" {
			name = "unknown"
		}
		if id == "" {
			id = "n/a"
		}
		entries = append(entries, fmt.Sprintf("%s (id=%s)", name, id))
	}
	return "existing virtual servers: " + strings.Join(entries, ", ")
}

func findVirtualServerByName(servers []map[string]string, name string) bool {
	needle := strings.TrimSpace(strings.ToLower(name))
	if needle == "" {
		return false
	}
	for _, server := range servers {
		serverName := strings.TrimSpace(strings.ToLower(resolveVirtualServerValue(server, "virtualserver_name", "server_name", "name")))
		if serverName != "" && serverName == needle {
			return true
		}
	}
	return false
}

func resolveVirtualServerValue(server map[string]string, keys ...string) string {
	for _, key := range keys {
		if value := strings.TrimSpace(server[key]); value != "" {
			return value
		}
	}
	return ""
}

func normalizeVirtualServerList(servers []map[string]string) []map[string]any {
	results := make([]map[string]any, 0, len(servers))
	for _, server := range servers {
		entry := normalizeVirtualServerEntry(server)
		if entry == nil {
			continue
		}
		results = append(results, entry)
	}
	return results
}

func normalizeVirtualServerEntry(server map[string]string) map[string]any {
	sidValue := resolveVirtualServerValue(server, "virtualserver_id", "sid", "server_id", "id")
	name := resolveVirtualServerValue(server, "virtualserver_name", "server_name", "name")
	if sidValue == "" && name == "" {
		return nil
	}

	return map[string]any{
		"sid":               parseOptionalInt(sidValue),
		"name":              name,
		"voice_port":        parseOptionalInt(resolveVirtualServerValue(server, "virtualserver_port", "voice_port", "port", "server_port")),
		"filetransfer_port": parseOptionalInt(resolveVirtualServerValue(server, "virtualserver_filetransfer_port", "filetransfer_port", "file_port")),
		"slots":             parseOptionalInt(resolveVirtualServerValue(server, "virtualserver_maxclients", "slots", "max_clients", "clients_max")),
		"status":            resolveVirtualServerValue(server, "virtualserver_status", "status"),
	}
}

func parseOptionalInt(value string) int {
	if value == "" {
		return 0
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return 0
	}
	return parsed
}
