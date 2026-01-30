package main

import (
	"bytes"
	"encoding/binary"
	"fmt"
	"net"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const a2sQueryTimeout = 3 * time.Second

func handleInstanceQueryCheck(job jobs.Job) (jobs.Result, func() error) {
	queryType := strings.ToLower(payloadValue(job.Payload, "query_type"))
	host := payloadValue(job.Payload, "host", "ip")
	gamePort := payloadValue(job.Payload, "game_port")
	queryPort := payloadValue(job.Payload, "query_port")
	port := queryPort
	if port == "" {
		port = gamePort
	}

	missing := missingValues([]requiredValue{
		{key: "host", value: host},
		{key: "port", value: port},
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
		result, err := queryA2S(host, port)
		if err != nil {
			return jobs.Result{
				JobID:     job.ID,
				Status:    "success",
				Output:    map[string]string{"status": "offline", "message": err.Error()},
				Completed: time.Now().UTC(),
			}, nil
		}
		return jobs.Result{
			JobID:     job.ID,
			Status:    "success",
			Output:    result,
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

func queryA2S(host, port string) (map[string]string, error) {
	portNum, err := strconv.Atoi(port)
	if err != nil || portNum <= 0 || portNum > 65535 {
		return nil, fmt.Errorf("invalid port %q", port)
	}

	address := net.JoinHostPort(host, strconv.Itoa(portNum))
	conn, err := net.DialTimeout("udp", address, a2sQueryTimeout)
	if err != nil {
		return nil, fmt.Errorf("dial udp: %w", err)
	}
	defer func() {
		_ = conn.Close()
	}()

	if err := conn.SetDeadline(time.Now().Add(a2sQueryTimeout)); err != nil {
		return nil, err
	}

	payload := append([]byte{0xFF, 0xFF, 0xFF, 0xFF}, []byte("TSource Engine Query\x00")...)
	if _, err := conn.Write(payload); err != nil {
		return nil, fmt.Errorf("send query: %w", err)
	}

	buffer := make([]byte, 1400)
	n, err := conn.Read(buffer)
	if err != nil {
		return nil, fmt.Errorf("read response: %w", err)
	}

	players, maxPlayers, mapName, err := parseA2SInfo(buffer[:n])
	if err != nil {
		return nil, err
	}

	return map[string]string{
		"status":      "running",
		"players":     strconv.Itoa(players),
		"max_players": strconv.Itoa(maxPlayers),
		"map":         mapName,
	}, nil
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

func readA2SShort(payload []byte, offset int) (uint16, int) {
	if offset+2 > len(payload) {
		return 0, offset
	}
	value := binary.LittleEndian.Uint16(payload[offset : offset+2])
	return value, offset + 2
}
