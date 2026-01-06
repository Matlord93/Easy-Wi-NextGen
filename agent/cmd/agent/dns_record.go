package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleDNSRecordCreate(job jobs.Job) (jobs.Result, func() error) {
	return handleDNSRecordChange(job, "REPLACE", true)
}

func handleDNSRecordUpdate(job jobs.Job) (jobs.Result, func() error) {
	return handleDNSRecordChange(job, "REPLACE", true)
}

func handleDNSRecordDelete(job jobs.Job) (jobs.Result, func() error) {
	return handleDNSRecordChange(job, "DELETE", false)
}

func handleDNSRecordChange(job jobs.Job, changeType string, requireContent bool) (jobs.Result, func() error) {
	zoneName := normalizeZoneName(payloadValue(job.Payload, "zone", "zone_name", "domain"))
	recordName := payloadValue(job.Payload, "record_name", "name", "record")
	recordType := strings.ToUpper(payloadValue(job.Payload, "type", "record_type"))
	content := payloadValue(job.Payload, "content", "value")
	ttlValue := payloadValue(job.Payload, "ttl")
	priority := payloadValue(job.Payload, "priority")
	apiURL := payloadValue(job.Payload, "api_url", "pdns_api_url")
	apiKey := payloadValue(job.Payload, "api_key", "pdns_api_key")
	serverID := payloadValue(job.Payload, "server_id", "pdns_server")

	if apiURL == "" {
		apiURL = defaultPowerDNSURL
	}
	if serverID == "" {
		serverID = defaultPowerDNSServer
	}

	recordName = normalizeRecordName(recordName, zoneName)

	missing := missingValues([]requiredValue{
		{key: "zone", value: zoneName},
		{key: "record_name", value: recordName},
		{key: "type", value: recordType},
	})
	if requireContent {
		missing = append(missing, missingValues([]requiredValue{
			{key: "content", value: content},
			{key: "ttl", value: ttlValue},
		})...)
	}

	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	ttl := 3600
	if ttlValue != "" {
		parsed, err := strconv.Atoi(ttlValue)
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("invalid ttl: %w", err))
		}
		ttl = parsed
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

	normalizedContent := normalizeRecordContent(recordType, content, priority)
	records := []powerDNSRRRecord{}
	if changeType != "DELETE" {
		records = append(records, powerDNSRRRecord{Content: normalizedContent, Disabled: false})
	}

	payload := powerDNSZonePatchRequest{
		RRSets: []powerDNSRRSet{
			{
				Name:       recordName,
				Type:       recordType,
				ChangeType: changeType,
				TTL:        ttl,
				Records:    records,
			},
		},
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("encode PowerDNS record payload: %w", err))
	}

	endpoint := fmt.Sprintf("%s/api/v1/servers/%s/zones/%s", strings.TrimRight(apiURL, "/"), serverID, zoneName)
	request, err := http.NewRequest(http.MethodPatch, endpoint, bytes.NewReader(body))
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("build PowerDNS record request: %w", err))
	}
	request.Header.Set("X-API-Key", apiKey)
	request.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	response, err := client.Do(request)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("PowerDNS record request failed: %w", err))
	}
	defer response.Body.Close()

	responseBody, err := io.ReadAll(response.Body)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("read PowerDNS record response: %w", err))
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return failureResult(job.ID, fmt.Errorf("PowerDNS record request failed: %s", strings.TrimSpace(string(responseBody))))
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"zone_name":   zoneName,
			"record_name": recordName,
			"record_type": recordType,
			"ttl":         strconv.Itoa(ttl),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func normalizeRecordName(recordName, zoneName string) string {
	recordName = strings.TrimSpace(recordName)
	if recordName == "" || recordName == "@" {
		return zoneName
	}
	if strings.HasSuffix(recordName, ".") {
		return recordName
	}
	if zoneName == "" {
		return recordName
	}
	return recordName + "." + zoneName
}

func normalizeRecordContent(recordType, content, priority string) string {
	content = strings.TrimSpace(content)
	if content == "" {
		return content
	}

	switch strings.ToUpper(recordType) {
	case "CNAME", "NS":
		if !strings.HasSuffix(content, ".") {
			content += "."
		}
	case "MX":
		if !strings.Contains(content, " ") && !strings.HasSuffix(content, ".") {
			content += "."
		}
		if priority != "" && !strings.HasPrefix(content, priority+" ") {
			content = priority + " " + content
		}
	}

	return content
}
