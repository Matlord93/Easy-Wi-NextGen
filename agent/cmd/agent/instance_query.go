package main

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const a2sQueryTimeout = 3 * time.Second
const minecraftQueryTimeout = 4 * time.Second

const (
	a2sHeaderSimple  int32 = -1
	a2sHeaderSplit   int32 = -2
	a2sTypeInfoReply       = 0x49
	a2sTypeChallenge       = 0x41
)

var bedrockMagic = []byte{0x00, 0xff, 0xff, 0x00, 0xfe, 0xfe, 0xfe, 0xfe, 0xfd, 0xfd, 0xfd, 0xfd, 0x12, 0x34, 0x56, 0x78}

var queryA2SDebugEnabled = strings.EqualFold(strings.TrimSpace(os.Getenv("QUERY_A2S_DEBUG")), "1") || strings.EqualFold(strings.TrimSpace(os.Getenv("QUERY_A2S_DEBUG")), "true")
var queryPayloadDebugEnabled = strings.EqualFold(strings.TrimSpace(os.Getenv("QUERY_PAYLOAD_DEBUG")), "1") || strings.EqualFold(strings.TrimSpace(os.Getenv("QUERY_PAYLOAD_DEBUG")), "true")

func handleInstanceQueryCheck(job jobs.Job) (jobs.Result, func() error) {
	queryType := strings.ToLower(payloadValue(job.Payload, "query_type", "protocol"))
	if queryPayloadDebugEnabled {
		payloadJSON, _ := json.Marshal(job.Payload)
		log.Printf("instance.query.check payload: job_id=%s payload=%s", job.ID, payloadJSON)
	}
	resolution := resolveQueryDialHost(
		payloadValue(job.Payload, "host", "ip"),
		payloadValue(job.Payload, "bind_ip", "query_bind_ip"),
		payloadValue(job.Payload, "instance_ip"),
		payloadValue(job.Payload, "node_ip", "public_ip"),
		payloadValue(job.Payload, "local_only", "is_local_only"),
		payloadValue(job.Payload, "network_mode"),
		payloadValue(job.Payload, "share_host_network"),
	)
	host := resolution.Host
	gamePort := payloadValue(job.Payload, "game_port")
	queryPort := payloadValue(job.Payload, "query_port")
	port := queryPort
	if port == "" {
		port = gamePort
	}

	missing := missingValues([]requiredValue{
		{key: "host", value: host},
		{key: "port", value: port},
		{key: "protocol", value: queryType},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	switch queryType {
	case "a2s", "steam_a2s":
		startedAt := time.Now()
		result, err := queryA2S(host, port)
		if err != nil {
			return jobs.Result{
				JobID:     job.ID,
				Status:    "success",
				Output:    buildQueryOutput("offline", "source", err.Error(), startedAt, nil),
				Completed: time.Now().UTC(),
			}, nil
		}
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    buildQueryOutput("running", "source", "", startedAt, result),
			Completed: time.Now().UTC(),
		}, nil
	case "minecraft_java", "minecraft":
		startedAt := time.Now()
		result, err := queryMinecraftJava(host, port)
		if err != nil {
			return jobs.Result{
				JobID:     job.ID,
				Status:    "success",
				Output:    buildQueryOutput("offline", "minecraft_java", err.Error(), startedAt, nil),
				Completed: time.Now().UTC(),
			}, nil
		}
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    buildQueryOutput("online", "minecraft_java", "", startedAt, result),
			Completed: time.Now().UTC(),
		}, nil
	case "minecraft_bedrock", "bedrock":
		startedAt := time.Now()
		result, err := queryMinecraftBedrock(host, port)
		if err != nil {
			return jobs.Result{
				JobID:     job.ID,
				Status:    "success",
				Output:    buildQueryOutput("offline", "minecraft_bedrock", err.Error(), startedAt, nil),
				Completed: time.Now().UTC(),
			}, nil
		}
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    buildQueryOutput("online", "minecraft_bedrock", "", startedAt, result),
			Completed: time.Now().UTC(),
		}, nil
	case "none", "":
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    map[string]string{"status": "unknown", "message": "query type not configured"},
			Completed: time.Now().UTC(),
		}, nil
	default:
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "unsupported query type"},
			Completed: time.Now().UTC(),
		}, nil
	}
}

func normalizeQueryDialHost(host string) string {
	normalized := strings.TrimSpace(host)
	switch normalized {
	case "", "0.0.0.0", "::", "*":
		return "127.0.0.1"
	}

	ip := net.ParseIP(normalized)
	if ip == nil {
		return normalized
	}

	if ip.IsLoopback() {
		return normalized
	}

	if isLocalIP(ip) {
		if ip.To4() != nil {
			return "127.0.0.1"
		}
		return "::1"
	}

	return normalized
}

type queryDialResolution struct {
	Host         string
	Source       string
	NetworkMode  string
	LoopbackUsed bool
}

func resolveQueryDialHost(host, bindIP, instanceIP, nodeIP, localOnly, networkMode, shareHostNetwork string) queryDialResolution {
	mode := normalizeNetworkMode(networkMode, shareHostNetwork)
	localOnlyNormalized := strings.EqualFold(strings.TrimSpace(localOnly), "1") || strings.EqualFold(strings.TrimSpace(localOnly), "true") || strings.EqualFold(strings.TrimSpace(localOnly), "yes")
	if mode == "isolated" {
		if normalized := normalizeQueryDialHost(bindIP); normalized != "" && !isLoopbackHost(normalized) {
			return newQueryDialResolution(normalized, "bind_ip", mode)
		}
		if normalized := normalizeQueryDialHost(host); normalized != "" && !isLoopbackHost(normalized) {
			return newQueryDialResolution(normalized, "instance_ip", mode)
		}
		if normalized := normalizeQueryDialHost(instanceIP); normalized != "" && !isLoopbackHost(normalized) {
			return newQueryDialResolution(normalized, "instance_ip", mode)
		}
		if normalized := normalizeQueryDialHost(nodeIP); normalized != "" && !isLoopbackHost(normalized) {
			return newQueryDialResolution(normalized, "node_ip", mode)
		}
		return newQueryDialResolution("", "", mode)
	}

	if normalized := normalizeQueryDialHost(bindIP); normalized != "" && normalized != "127.0.0.1" {
		return newQueryDialResolution(normalized, "bind_ip", mode)
	}
	if normalized := normalizeQueryDialHost(host); normalized != "" && normalized != "127.0.0.1" {
		return newQueryDialResolution(normalized, "instance_ip", mode)
	}
	if normalized := normalizeQueryDialHost(instanceIP); normalized != "" && normalized != "127.0.0.1" {
		return newQueryDialResolution(normalized, "instance_ip", mode)
	}
	if !localOnlyNormalized {
		if normalized := normalizeQueryDialHost(nodeIP); normalized != "" {
			return newQueryDialResolution(normalized, "node_ip", mode)
		}
	}
	if normalized := normalizeQueryDialHost(bindIP); normalized != "" {
		return newQueryDialResolution(normalized, "bind_ip", mode)
	}
	if normalized := normalizeQueryDialHost(host); normalized != "" {
		return newQueryDialResolution(normalized, "instance_ip", mode)
	}
	if normalized := normalizeQueryDialHost(instanceIP); normalized != "" {
		return newQueryDialResolution(normalized, "instance_ip", mode)
	}
	if mode == "host" {
		return newQueryDialResolution("127.0.0.1", "loopback", mode)
	}
	return newQueryDialResolution("", "", mode)
}

func normalizeNetworkMode(networkMode, shareHostNetwork string) string {
	normalized := strings.ToLower(strings.TrimSpace(networkMode))
	switch normalized {
	case "host", "isolated":
		return normalized
	}

	if strings.EqualFold(strings.TrimSpace(shareHostNetwork), "1") || strings.EqualFold(strings.TrimSpace(shareHostNetwork), "true") || strings.EqualFold(strings.TrimSpace(shareHostNetwork), "yes") {
		return "host"
	}

	return "isolated"
}

func isLoopbackHost(host string) bool {
	if host == "127.0.0.1" || host == "::1" || strings.EqualFold(host, "localhost") {
		return true
	}
	parsed := net.ParseIP(host)
	return parsed != nil && parsed.IsLoopback()
}

func newQueryDialResolution(host, source, mode string) queryDialResolution {
	if source == "" && host != "" {
		source = "instance_ip"
	}
	if source == "" {
		source = "loopback"
	}
	return queryDialResolution{Host: host, Source: source, NetworkMode: mode, LoopbackUsed: isLoopbackHost(host)}
}

func isLocalIP(target net.IP) bool {
	if target == nil {
		return false
	}

	interfaces, err := net.Interfaces()
	if err != nil {
		return false
	}

	for _, iface := range interfaces {
		addrs, addrErr := iface.Addrs()
		if addrErr != nil {
			continue
		}
		for _, addr := range addrs {
			ipNet, ok := addr.(*net.IPNet)
			if !ok || ipNet.IP == nil {
				continue
			}
			if ipNet.IP.Equal(target) {
				return true
			}
		}
	}

	return false
}

func buildQueryOutput(status, engine, message string, startedAt time.Time, data map[string]string) map[string]string {
	output := map[string]string{
		"status":     status,
		"engine":     engine,
		"latency_ms": strconv.FormatInt(time.Since(startedAt).Milliseconds(), 10),
	}
	if message != "" {
		output["message"] = message
	}
	for key, value := range data {
		if value != "" {
			output[key] = value
		}
	}
	return output
}

func queryA2S(host, port string) (map[string]string, error) {
	portNum, err := strconv.Atoi(port)
	if err != nil || portNum <= 0 || portNum > 65535 {
		return nil, fmt.Errorf("invalid port %q", port)
	}

	address := net.JoinHostPort(host, strconv.Itoa(portNum))
	if queryA2SDebugEnabled {
		log.Printf("a2s debug: dial_host=%s dial_port=%d", host, portNum)
	}
	conn, err := net.DialTimeout("udp", address, a2sQueryTimeout)
	if err != nil {
		return nil, fmt.Errorf("dial udp: %w", err)
	}
	defer func() {
		_ = conn.Close()
	}()

	payload, err := queryA2SInfo(conn)
	if err != nil {
		return nil, err
	}

	players, maxPlayers, mapName, err := parseA2SInfo(payload)
	if err != nil {
		return nil, err
	}

	if playerCount, playerErr := queryA2SPlayersCount(conn); playerErr == nil {
		players = playerCount
	}

	return map[string]string{
		"players":     strconv.Itoa(players),
		"max_players": strconv.Itoa(maxPlayers),
		"map":         mapName,
	}, nil
}

func queryA2SInfo(conn net.Conn) ([]byte, error) {
	request := append([]byte{0xFF, 0xFF, 0xFF, 0xFF}, []byte("TSource Engine Query\x00")...)
	if queryA2SDebugEnabled {
		log.Printf("a2s debug: bytes_sent=%d", len(request))
	}
	if _, err := conn.Write(request); err != nil {
		return nil, fmt.Errorf("send query: %w", err)
	}

	retryTimeout := false
	challengeRetry := false
	for {
		packet, err := readA2SPacket(conn)
		if err != nil {
			netErr := &net.OpError{}
			if errors.As(err, &netErr) && netErr.Timeout() && !retryTimeout {
				retryTimeout = true
				if _, writeErr := conn.Write(request); writeErr != nil {
					return nil, fmt.Errorf("resend query: %w", writeErr)
				}
				continue
			}
			return nil, err
		}
		if len(packet) < 5 {
			return nil, fmt.Errorf("response too short")
		}
		if queryA2SDebugEnabled {
			preview := packet
			if len(preview) > 8 {
				preview = preview[:8]
			}
			log.Printf("a2s debug: recv_first8=%x", preview)
		}
		if packet[4] == a2sTypeChallenge {
			if challengeRetry {
				return nil, fmt.Errorf("unexpected repeated challenge response")
			}
			if len(packet) < 9 {
				return nil, fmt.Errorf("invalid challenge response")
			}
			challengeRetry = true
			challengeRequest := append(append([]byte{}, request...), packet[5:9]...)
			if _, err := conn.Write(challengeRequest); err != nil {
				return nil, fmt.Errorf("send query with challenge: %w", err)
			}
			continue
		}
		return packet, nil
	}
}

func readA2SPacket(conn net.Conn) ([]byte, error) {
	if err := conn.SetDeadline(time.Now().Add(a2sQueryTimeout)); err != nil {
		return nil, err
	}
	if queryA2SDebugEnabled {
		log.Printf("a2s debug: read_attempt deadline_ms=%d", a2sQueryTimeout.Milliseconds())
	}
	buffer := make([]byte, 4096)
	n, err := conn.Read(buffer)
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}
	packet := append([]byte(nil), buffer[:n]...)
	if len(packet) < 4 {
		return nil, fmt.Errorf("response too short")
	}
	header := int32(binary.LittleEndian.Uint32(packet[:4]))
	if header == a2sHeaderSimple {
		return packet, nil
	}
	if header != a2sHeaderSplit {
		return nil, fmt.Errorf("invalid response header")
	}
	return readA2SSplitPacket(conn, packet)
}

func readA2SSplitPacket(conn net.Conn, first []byte) ([]byte, error) {
	fragments := map[byte][]byte{}
	packetID := int32(0)
	total := byte(0)
	readFragment := func(packet []byte) error {
		if len(packet) < 12 {
			return fmt.Errorf("split packet too short")
		}
		if int32(binary.LittleEndian.Uint32(packet[:4])) != a2sHeaderSplit {
			return fmt.Errorf("invalid split packet header")
		}
		id := int32(binary.LittleEndian.Uint32(packet[4:8]))
		fragTotal := packet[8]
		fragNumber := packet[9]
		if total == 0 {
			total = fragTotal
			packetID = id
		}
		if id != packetID || fragTotal != total {
			return fmt.Errorf("mismatched split packet")
		}
		payloadOffset := 10
		if fragNumber == 0 {
			payloadOffset = 12
		}
		if payloadOffset > len(packet) {
			return fmt.Errorf("invalid split packet payload")
		}
		fragments[fragNumber] = append([]byte(nil), packet[payloadOffset:]...)
		return nil
	}
	if err := readFragment(first); err != nil {
		return nil, err
	}
	for byte(len(fragments)) < total {
		if err := conn.SetDeadline(time.Now().Add(a2sQueryTimeout)); err != nil {
			return nil, err
		}
		buffer := make([]byte, 4096)
		n, err := conn.Read(buffer)
		if err != nil {
			return nil, fmt.Errorf("read split response: %w", err)
		}
		if err := readFragment(buffer[:n]); err != nil {
			return nil, err
		}
	}
	assembled := []byte{0xFF, 0xFF, 0xFF, 0xFF}
	for i := byte(0); i < total; i++ {
		part, ok := fragments[i]
		if !ok {
			return nil, fmt.Errorf("missing split packet fragment %d", i)
		}
		assembled = append(assembled, part...)
	}
	return assembled, nil
}

func parseA2SInfo(payload []byte) (int, int, string, error) {
	if len(payload) < 5 {
		return 0, 0, "", fmt.Errorf("response too short")
	}
	if !bytes.Equal(payload[:4], []byte{0xFF, 0xFF, 0xFF, 0xFF}) {
		return 0, 0, "", fmt.Errorf("invalid response header")
	}
	if payload[4] != 0x49 {
		return 0, 0, "", fmt.Errorf("invalid response type")
	}

	offset := 5
	if offset >= len(payload) {
		return 0, 0, "", fmt.Errorf("response truncated")
	}
	offset += 1 // protocol
	_, offset = readA2SString(payload, offset)
	mapName, offset := readA2SString(payload, offset)
	_, offset = readA2SString(payload, offset)
	_, offset = readA2SString(payload, offset)

	if offset+2 > len(payload) {
		return 0, 0, "", fmt.Errorf("response truncated")
	}
	offset += 2 // app id

	if offset+2 > len(payload) {
		return 0, 0, "", fmt.Errorf("response truncated")
	}
	players := int(payload[offset])
	maxPlayers := int(payload[offset+1])

	return players, maxPlayers, mapName, nil
}

func queryA2SPlayersCount(conn net.Conn) (int, error) {
	if err := conn.SetDeadline(time.Now().Add(a2sQueryTimeout)); err != nil {
		return 0, err
	}

	challengeRequest := []byte{0xFF, 0xFF, 0xFF, 0xFF, 0x55, 0xFF, 0xFF, 0xFF, 0xFF}
	if _, err := conn.Write(challengeRequest); err != nil {
		return 0, fmt.Errorf("send challenge: %w", err)
	}

	buffer := make([]byte, 1400)
	n, err := conn.Read(buffer)
	if err != nil {
		return 0, fmt.Errorf("read challenge: %w", err)
	}
	if n < 9 || buffer[4] != 0x41 {
		return 0, fmt.Errorf("invalid challenge response")
	}

	challenge := buffer[5:9]
	playerRequest := append([]byte{0xFF, 0xFF, 0xFF, 0xFF, 0x55}, challenge...)
	if _, err := conn.Write(playerRequest); err != nil {
		return 0, fmt.Errorf("send player request: %w", err)
	}

	n, err = conn.Read(buffer)
	if err != nil {
		return 0, fmt.Errorf("read player response: %w", err)
	}
	if n < 6 || buffer[4] != 0x44 {
		return 0, fmt.Errorf("invalid player response")
	}

	return int(buffer[5]), nil
}

func readA2SString(payload []byte, offset int) (string, int) {
	if offset >= len(payload) {
		return "", offset
	}
	end := bytes.IndexByte(payload[offset:], 0x00)
	if end == -1 {
		return "", len(payload)
	}
	value := string(payload[offset : offset+end])
	return value, offset + end + 1
}

func queryMinecraftJava(host, port string) (map[string]string, error) {
	portNum, err := strconv.Atoi(port)
	if err != nil || portNum <= 0 || portNum > 65535 {
		return nil, fmt.Errorf("invalid port %q", port)
	}

	address := net.JoinHostPort(host, strconv.Itoa(portNum))
	conn, err := net.DialTimeout("tcp", address, minecraftQueryTimeout)
	if err != nil {
		return nil, fmt.Errorf("dial tcp: %w", err)
	}
	defer func() {
		_ = conn.Close()
	}()

	if err := conn.SetDeadline(time.Now().Add(minecraftQueryTimeout)); err != nil {
		return nil, err
	}

	if err := sendMinecraftHandshake(conn, host, portNum); err != nil {
		return nil, err
	}

	if err := sendMinecraftStatusRequest(conn); err != nil {
		return nil, err
	}

	payload, err := readMinecraftPacket(conn)
	if err != nil {
		return nil, err
	}

	status, err := parseMinecraftStatus(payload)
	if err != nil {
		return nil, err
	}

	output := map[string]string{
		"players":     strconv.Itoa(status.Players),
		"max_players": strconv.Itoa(status.MaxPlayers),
	}
	if status.Motd != "" {
		output["motd"] = status.Motd
	}
	if status.Version != "" {
		output["version"] = status.Version
	}

	return output, nil
}

func queryMinecraftBedrock(host, port string) (map[string]string, error) {
	portNum, err := strconv.Atoi(port)
	if err != nil || portNum <= 0 || portNum > 65535 {
		return nil, fmt.Errorf("invalid port %q", port)
	}

	address := net.JoinHostPort(host, strconv.Itoa(portNum))
	conn, err := net.DialTimeout("udp", address, minecraftQueryTimeout)
	if err != nil {
		return nil, fmt.Errorf("dial udp: %w", err)
	}
	defer func() {
		_ = conn.Close()
	}()

	if err := conn.SetDeadline(time.Now().Add(minecraftQueryTimeout)); err != nil {
		return nil, err
	}

	payload := &bytes.Buffer{}
	payload.WriteByte(0x01)
	_ = binary.Write(payload, binary.BigEndian, time.Now().UnixMilli())
	payload.Write(bedrockMagic)
	_ = binary.Write(payload, binary.BigEndian, time.Now().UnixNano())

	if _, err := conn.Write(payload.Bytes()); err != nil {
		return nil, fmt.Errorf("send ping: %w", err)
	}

	buffer := make([]byte, 2048)
	n, err := conn.Read(buffer)
	if err != nil {
		return nil, fmt.Errorf("read pong: %w", err)
	}

	status, err := parseBedrockStatus(buffer[:n])
	if err != nil {
		return nil, err
	}

	output := map[string]string{
		"players":     strconv.Itoa(status.Players),
		"max_players": strconv.Itoa(status.MaxPlayers),
	}
	if status.Motd != "" {
		output["motd"] = status.Motd
	}
	if status.Version != "" {
		output["version"] = status.Version
	}
	if status.Map != "" {
		output["map"] = status.Map
	}

	return output, nil
}

func sendMinecraftHandshake(conn net.Conn, host string, port int) error {
	payload := &bytes.Buffer{}
	if err := writeVarInt(payload, 0x00); err != nil {
		return err
	}
	if err := writeVarInt(payload, 754); err != nil {
		return err
	}
	if err := writeVarString(payload, host); err != nil {
		return err
	}
	if err := binary.Write(payload, binary.BigEndian, uint16(port)); err != nil {
		return err
	}
	if err := writeVarInt(payload, 0x01); err != nil {
		return err
	}

	return writeMinecraftPacket(conn, payload.Bytes())
}

func sendMinecraftStatusRequest(conn net.Conn) error {
	return writeMinecraftPacket(conn, []byte{0x00})
}

func writeMinecraftPacket(conn net.Conn, payload []byte) error {
	length := &bytes.Buffer{}
	if err := writeVarInt(length, len(payload)); err != nil {
		return err
	}
	packet := append(length.Bytes(), payload...)
	_, err := conn.Write(packet)
	return err
}

func readMinecraftPacket(conn net.Conn) ([]byte, error) {
	length, err := readVarInt(conn)
	if err != nil {
		return nil, err
	}
	if length <= 0 || length > 1<<20 {
		return nil, fmt.Errorf("invalid packet length %d", length)
	}

	buffer := make([]byte, length)
	if _, err := io.ReadFull(conn, buffer); err != nil {
		return nil, err
	}
	return buffer, nil
}

type minecraftStatus struct {
	Players    int
	MaxPlayers int
	Version    string
	Motd       string
}

type bedrockStatus struct {
	Players    int
	MaxPlayers int
	Version    string
	Motd       string
	Map        string
}

func parseMinecraftStatus(payload []byte) (minecraftStatus, error) {
	buffer := bytes.NewBuffer(payload)
	packetID, err := readVarIntFromBuffer(buffer)
	if err != nil {
		return minecraftStatus{}, err
	}
	if packetID != 0x00 {
		return minecraftStatus{}, fmt.Errorf("unexpected packet id %d", packetID)
	}

	jsonPayload, err := readVarStringFromBuffer(buffer)
	if err != nil {
		return minecraftStatus{}, err
	}

	var response map[string]any
	if err := json.Unmarshal([]byte(jsonPayload), &response); err != nil {
		return minecraftStatus{}, err
	}

	status := minecraftStatus{}
	if players, ok := response["players"].(map[string]any); ok {
		if online, ok := players["online"].(float64); ok {
			status.Players = int(online)
		}
		if max, ok := players["max"].(float64); ok {
			status.MaxPlayers = int(max)
		}
	}
	if version, ok := response["version"].(map[string]any); ok {
		if name, ok := version["name"].(string); ok {
			status.Version = name
		}
	}
	status.Motd = parseMinecraftMotd(response["description"])

	return status, nil
}

func parseMinecraftMotd(value any) string {
	switch motd := value.(type) {
	case string:
		return motd
	case map[string]any:
		text := ""
		if base, ok := motd["text"].(string); ok {
			text = base
		}
		if extra, ok := motd["extra"].([]any); ok {
			for _, entry := range extra {
				if fragment, ok := entry.(map[string]any); ok {
					if fragmentText, ok := fragment["text"].(string); ok {
						text += fragmentText
					}
				}
			}
		}
		return strings.TrimSpace(text)
	default:
		return ""
	}
}

func parseBedrockStatus(payload []byte) (bedrockStatus, error) {
	if len(payload) < 35 {
		return bedrockStatus{}, fmt.Errorf("response too short")
	}
	if payload[0] != 0x1c {
		return bedrockStatus{}, fmt.Errorf("invalid response type")
	}

	offset := 1 + 8 + 8 + len(bedrockMagic)
	if offset+2 > len(payload) {
		return bedrockStatus{}, fmt.Errorf("response truncated")
	}
	infoLen := int(binary.BigEndian.Uint16(payload[offset : offset+2]))
	offset += 2
	if offset+infoLen > len(payload) {
		return bedrockStatus{}, fmt.Errorf("invalid info length")
	}
	info := string(payload[offset : offset+infoLen])
	parts := strings.Split(info, ";")
	if len(parts) < 6 {
		return bedrockStatus{}, fmt.Errorf("invalid info payload")
	}

	status := bedrockStatus{
		Motd: parts[1],
	}
	if len(parts) > 3 {
		status.Version = parts[3]
	}
	if len(parts) > 4 {
		if players, err := strconv.Atoi(parts[4]); err == nil {
			status.Players = players
		}
	}
	if len(parts) > 5 {
		if maxPlayers, err := strconv.Atoi(parts[5]); err == nil {
			status.MaxPlayers = maxPlayers
		}
	}
	if len(parts) > 7 {
		status.Map = parts[7]
	}

	return status, nil
}

func writeVarInt(buffer *bytes.Buffer, value int) error {
	for {
		temp := byte(value & 0x7F)
		value >>= 7
		if value != 0 {
			temp |= 0x80
		}
		if err := buffer.WriteByte(temp); err != nil {
			return err
		}
		if value == 0 {
			return nil
		}
	}
}

func readVarInt(reader io.Reader) (int, error) {
	var value int
	var position uint
	for {
		var buf [1]byte
		if _, err := io.ReadFull(reader, buf[:]); err != nil {
			return 0, err
		}
		current := buf[0]
		value |= int(current&0x7F) << position
		if current&0x80 == 0 {
			return value, nil
		}
		position += 7
		if position > 35 {
			return 0, fmt.Errorf("varint too long")
		}
	}
}

func readVarIntFromBuffer(buffer *bytes.Buffer) (int, error) {
	var value int
	var position uint
	for {
		current, err := buffer.ReadByte()
		if err != nil {
			return 0, err
		}
		value |= int(current&0x7F) << position
		if current&0x80 == 0 {
			return value, nil
		}
		position += 7
		if position > 35 {
			return 0, fmt.Errorf("varint too long")
		}
	}
}

func writeVarString(buffer *bytes.Buffer, value string) error {
	if err := writeVarInt(buffer, len(value)); err != nil {
		return err
	}
	_, err := buffer.WriteString(value)
	return err
}

func readVarStringFromBuffer(buffer *bytes.Buffer) (string, error) {
	length, err := readVarIntFromBuffer(buffer)
	if err != nil {
		return "", err
	}
	if length < 0 || length > buffer.Len() {
		return "", fmt.Errorf("invalid string length")
	}
	data := make([]byte, length)
	if _, err := io.ReadFull(buffer, data); err != nil {
		return "", err
	}
	return string(data), nil
}
