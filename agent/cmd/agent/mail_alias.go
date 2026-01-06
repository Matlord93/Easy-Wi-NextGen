package main

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	mailAliasDirMode  = 0o750
	mailAliasFileMode = 0o640
)

func handleMailAliasCreate(job jobs.Job) (jobs.Result, func() error) {
	return handleMailAliasUpsert(job, "created")
}

func handleMailAliasUpdate(job jobs.Job) (jobs.Result, func() error) {
	return handleMailAliasUpsert(job, "updated")
}

func handleMailAliasEnable(job jobs.Job) (jobs.Result, func() error) {
	return handleMailAliasStatus(job, true)
}

func handleMailAliasDisable(job jobs.Job) (jobs.Result, func() error) {
	return handleMailAliasStatus(job, false)
}

func handleMailAliasDelete(job jobs.Job) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "alias")
	mapPath := payloadValue(job.Payload, "map_path", "alias_map_path")

	missing := missingValues([]requiredValue{
		{key: "address", value: address},
		{key: "map_path", value: mapPath},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	entries, order, err := readAliasMap(mapPath)
	if err != nil {
		return failureResult(job.ID, err)
	}

	delete(entries, address)
	order = filterOrder(order, entries)

	if err := writeAliasMap(mapPath, entries, order); err != nil {
		return failureResult(job.ID, err)
	}

	if err := postmapAndReload(mapPath); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":  address,
			"map_path": mapPath,
			"action":   "deleted",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailAliasUpsert(job jobs.Job, action string) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "alias")
	destinationsValue := payloadValue(job.Payload, "destinations", "forward_to")
	mapPath := payloadValue(job.Payload, "map_path", "alias_map_path")
	enabledValue := payloadValue(job.Payload, "enabled")

	missing := missingValues([]requiredValue{
		{key: "address", value: address},
		{key: "destinations", value: destinationsValue},
		{key: "map_path", value: mapPath},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	destinations := parseAliasDestinations(destinationsValue)
	if len(destinations) == 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "destinations list is empty"},
			Completed: time.Now().UTC(),
		}, nil
	}

	enabled := normalizeMailboxEnabled(enabledValue, true)
	entries, order, err := readAliasMap(mapPath)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if enabled {
		entries[address] = strings.Join(destinations, ", ")
		if !containsOrder(order, address) {
			order = append(order, address)
		}
	} else {
		delete(entries, address)
		order = filterOrder(order, entries)
		action = "disabled"
	}

	if err := writeAliasMap(mapPath, entries, order); err != nil {
		return failureResult(job.ID, err)
	}

	if err := postmapAndReload(mapPath); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":      address,
			"destinations": strings.Join(destinations, ", "),
			"map_path":     mapPath,
			"action":       action,
			"enabled":      fmt.Sprintf("%t", enabled),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailAliasStatus(job jobs.Job, enabled bool) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "alias")
	mapPath := payloadValue(job.Payload, "map_path", "alias_map_path")

	missing := missingValues([]requiredValue{
		{key: "address", value: address},
		{key: "map_path", value: mapPath},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	entries, order, err := readAliasMap(mapPath)
	if err != nil {
		return failureResult(job.ID, err)
	}

	action := "disabled"
	if enabled {
		destinationValue := payloadValue(job.Payload, "destinations", "forward_to")
		destinations := parseAliasDestinations(destinationValue)
		if len(destinations) == 0 {
			return jobs.Result{
				JobID:     job.ID,
				Status:    "failed",
				Output:    map[string]string{"message": "destinations list is empty"},
				Completed: time.Now().UTC(),
			}, nil
		}
		entries[address] = strings.Join(destinations, ", ")
		if !containsOrder(order, address) {
			order = append(order, address)
		}
		action = "enabled"
	} else {
		delete(entries, address)
		order = filterOrder(order, entries)
	}

	if err := writeAliasMap(mapPath, entries, order); err != nil {
		return failureResult(job.ID, err)
	}

	if err := postmapAndReload(mapPath); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":  address,
			"map_path": mapPath,
			"action":   action,
			"enabled":  fmt.Sprintf("%t", enabled),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func readAliasMap(path string) (map[string]string, []string, error) {
	entries := make(map[string]string)
	var order []string

	file, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return entries, order, nil
		}
		return nil, nil, fmt.Errorf("open alias map %s: %w", path, err)
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		address := fields[0]
		destinations := strings.Join(fields[1:], " ")
		if _, exists := entries[address]; !exists {
			order = append(order, address)
		}
		entries[address] = destinations
	}

	if err := scanner.Err(); err != nil {
		return nil, nil, fmt.Errorf("scan alias map %s: %w", path, err)
	}

	return entries, order, nil
}

func writeAliasMap(path string, entries map[string]string, order []string) error {
	if err := ensureDirWithMode(filepath.Dir(path), mailAliasDirMode); err != nil {
		return err
	}

	var builder strings.Builder
	builder.WriteString("## Managed by Easy-Wi agent\n")

	used := make(map[string]struct{})
	for _, address := range order {
		destinations, ok := entries[address]
		if !ok {
			continue
		}
		builder.WriteString(fmt.Sprintf("%s %s\n", address, destinations))
		used[address] = struct{}{}
	}

	var remaining []string
	for address := range entries {
		if _, ok := used[address]; ok {
			continue
		}
		remaining = append(remaining, address)
	}
	sort.Strings(remaining)
	for _, address := range remaining {
		builder.WriteString(fmt.Sprintf("%s %s\n", address, entries[address]))
	}

	if err := os.WriteFile(path, []byte(builder.String()), mailAliasFileMode); err != nil {
		return fmt.Errorf("write alias map %s: %w", path, err)
	}
	return nil
}

func postmapAndReload(mapPath string) error {
	if err := runCommand("postmap", mapPath); err != nil {
		return fmt.Errorf("postmap %s: %w", mapPath, err)
	}
	if err := runCommand("systemctl", "reload", "postfix"); err != nil {
		if fallbackErr := runCommand("postfix", "reload"); fallbackErr != nil {
			return fmt.Errorf("reload postfix: %w", fallbackErr)
		}
	}
	return nil
}

func parseAliasDestinations(value string) []string {
	if value == "" {
		return nil
	}
	normalized := strings.NewReplacer("\n", ",", "\r", ",", ";", ",").Replace(value)
	parts := strings.Split(normalized, ",")
	var destinations []string
	for _, part := range parts {
		trimmed := strings.TrimSpace(part)
		if trimmed == "" {
			continue
		}
		destinations = append(destinations, trimmed)
	}
	return destinations
}

func containsOrder(order []string, address string) bool {
	for _, item := range order {
		if item == address {
			return true
		}
	}
	return false
}

func filterOrder(order []string, entries map[string]string) []string {
	var filtered []string
	for _, address := range order {
		if _, ok := entries[address]; ok {
			filtered = append(filtered, address)
		}
	}
	return filtered
}
