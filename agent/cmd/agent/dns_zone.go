package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	defaultPowerDNSURL    = "http://127.0.0.1:8081"
	defaultPowerDNSServer = "localhost"
	powerDNSKeyFile       = "/etc/easywi/pdns-api-key"
)

type powerDNSZoneResponse struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type powerDNSZoneCreateRequest struct {
	Name        string   `json:"name"`
	Kind        string   `json:"kind"`
	Nameservers []string `json:"nameservers"`
}

type powerDNSZonePatchRequest struct {
	RRSets []powerDNSRRSet `json:"rrsets"`
}

type powerDNSRRSet struct {
	Name       string             `json:"name"`
	Type       string             `json:"type"`
	ChangeType string             `json:"changetype"`
	TTL        int                `json:"ttl"`
	Records    []powerDNSRRRecord `json:"records"`
}

type powerDNSRRRecord struct {
	Content  string `json:"content"`
	Disabled bool   `json:"disabled"`
}

func handleDNSZoneCreate(job jobs.Job) (jobs.Result, func() error) {
	zoneName := normalizeZoneName(payloadValue(job.Payload, "zone", "zone_name", "name", "domain"))
	nameServerValue := payloadValue(job.Payload, "nameservers", "name_servers", "ns")
	apiURL := payloadValue(job.Payload, "api_url", "pdns_api_url")
	apiKey := payloadValue(job.Payload, "api_key", "pdns_api_key")
	serverID := payloadValue(job.Payload, "server_id", "pdns_server")

	if apiURL == "" {
		apiURL = defaultPowerDNSURL
	}
	if serverID == "" {
		serverID = defaultPowerDNSServer
	}

	nameServers := normalizeNameservers(nameServerValue)

	missing := missingValues([]requiredValue{
		{key: "zone", value: zoneName},
		{key: "nameservers", value: strings.Join(nameServers, ",")},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if apiKey == "" {
		key, err := os.ReadFile(powerDNSKeyPath())
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("read PowerDNS api key: %w", err))
		}
		apiKey = strings.TrimSpace(string(key))
	}
	if apiKey == "" {
		return failureResult(job.ID, fmt.Errorf("PowerDNS api key is empty"))
	}

	client := &http.Client{Timeout: 15 * time.Second}
	zoneID, err := createPowerDNSZone(client, apiURL, apiKey, serverID, zoneName, nameServers)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := setPowerDNSNSRecords(client, apiURL, apiKey, serverID, zoneName, nameServers); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"zone_id":     zoneID,
			"zone_name":   zoneName,
			"nameservers": strings.Join(nameServers, ","),
			"server_id":   serverID,
			"api_url":     apiURL,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func createPowerDNSZone(client *http.Client, apiURL, apiKey, serverID, zoneName string, nameServers []string) (string, error) {
	payload := powerDNSZoneCreateRequest{
		Name:        zoneName,
		Kind:        "Native",
		Nameservers: nameServers,
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return "", fmt.Errorf("encode PowerDNS zone payload: %w", err)
	}

	endpoint := fmt.Sprintf("%s/api/v1/servers/%s/zones", strings.TrimRight(apiURL, "/"), serverID)
	request, err := http.NewRequest(http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		return "", fmt.Errorf("build PowerDNS create zone request: %w", err)
	}
	request.Header.Set("X-API-Key", apiKey)
	request.Header.Set("Content-Type", "application/json")

	response, err := client.Do(request)
	if err != nil {
		return "", fmt.Errorf("PowerDNS create zone request failed: %w", err)
	}
	defer response.Body.Close()

	responseBody, err := io.ReadAll(response.Body)
	if err != nil {
		return "", fmt.Errorf("read PowerDNS create zone response: %w", err)
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return "", fmt.Errorf("PowerDNS create zone failed: %s", strings.TrimSpace(string(responseBody)))
	}

	var zone powerDNSZoneResponse
	if err := json.Unmarshal(responseBody, &zone); err != nil {
		return "", fmt.Errorf("decode PowerDNS create zone response: %w", err)
	}

	zoneID := zone.ID
	if zoneID == "" {
		zoneID = zone.Name
	}
	if zoneID == "" {
		zoneID = zoneName
	}
	return zoneID, nil
}

func setPowerDNSNSRecords(client *http.Client, apiURL, apiKey, serverID, zoneName string, nameServers []string) error {
	records := make([]powerDNSRRRecord, 0, len(nameServers))
	for _, ns := range nameServers {
		records = append(records, powerDNSRRRecord{Content: ns, Disabled: false})
	}

	payload := powerDNSZonePatchRequest{
		RRSets: []powerDNSRRSet{
			{
				Name:       zoneName,
				Type:       "NS",
				ChangeType: "REPLACE",
				TTL:        3600,
				Records:    records,
			},
		},
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("encode PowerDNS NS patch payload: %w", err)
	}

	endpoint := fmt.Sprintf("%s/api/v1/servers/%s/zones/%s", strings.TrimRight(apiURL, "/"), serverID, zoneName)
	request, err := http.NewRequest(http.MethodPatch, endpoint, bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("build PowerDNS NS patch request: %w", err)
	}
	request.Header.Set("X-API-Key", apiKey)
	request.Header.Set("Content-Type", "application/json")

	response, err := client.Do(request)
	if err != nil {
		return fmt.Errorf("PowerDNS NS patch request failed: %w", err)
	}
	defer response.Body.Close()

	responseBody, err := io.ReadAll(response.Body)
	if err != nil {
		return fmt.Errorf("read PowerDNS NS patch response: %w", err)
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return fmt.Errorf("PowerDNS NS patch failed: %s", strings.TrimSpace(string(responseBody)))
	}
	return nil
}

func normalizeZoneName(zoneName string) string {
	zoneName = strings.TrimSpace(zoneName)
	if zoneName == "" {
		return ""
	}
	if !strings.HasSuffix(zoneName, ".") {
		zoneName += "."
	}
	return zoneName
}

func normalizeNameservers(value string) []string {
	value = strings.TrimSpace(value)
	if value == "" {
		return nil
	}
	parts := strings.FieldsFunc(value, func(r rune) bool {
		return r == ',' || r == ';' || r == ' ' || r == '\n' || r == '\t'
	})
	seen := map[string]struct{}{}
	var result []string
	for _, part := range parts {
		trimmed := normalizeZoneName(part)
		if trimmed == "" {
			continue
		}
		if _, exists := seen[trimmed]; exists {
			continue
		}
		seen[trimmed] = struct{}{}
		result = append(result, trimmed)
	}
	return result
}

func powerDNSKeyPath() string {
	if path := os.Getenv("EASYWI_PDNS_API_KEY_FILE"); path != "" {
		return filepath.Clean(path)
	}
	return powerDNSKeyFile
}
