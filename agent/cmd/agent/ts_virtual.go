package main

import (
	"bufio"
	"fmt"
	"net"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

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

	if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	response, err := client.command(fmt.Sprintf("servertokenadd tokentype=0 tokenid1=%s tokenid2=0", sid))
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	token := response["token"]
	if token == "" {
		return orchestratorResult{status: "failed", errorText: "token rotate did not return token"}
	}

	return orchestratorResult{
		status:        "success",
		resultPayload: map[string]any{"token": token},
	}
}

func newTs3QueryClient(payload map[string]any) (*ts3QueryClient, error) {
	queryIP := payloadValue(payload, "query_bind_ip", "query_ip")
	if queryIP == "" {
		queryIP = "127.0.0.1"
	}
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
	client := &ts3QueryClient{
		conn:   conn,
		reader: bufio.NewReader(conn),
		writer: bufio.NewWriter(conn),
	}

	if err := client.drainResponse(); err != nil {
		_ = conn.Close()
		return nil, err
	}
	if _, err := client.command(fmt.Sprintf("login %s %s", escapeTs3Query(adminUser), escapeTs3Query(adminPass))); err != nil {
		_ = conn.Close()
		return nil, err
	}

	return client, nil
}

type ts3QueryClient struct {
	conn   net.Conn
	reader *bufio.Reader
	writer *bufio.Writer
}

func (client *ts3QueryClient) close() {
	if client == nil {
		return
	}
	_ = client.conn.Close()
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

func (client *ts3QueryClient) drainResponse() error {
	_, err := client.readResponse()
	return err
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
