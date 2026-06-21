package main

import (
	"strings"
	"testing"
)

func TestMusicbotStatusUsesRuntimeControlFallback(t *testing.T) {
	t.Parallel()
	job, _, _ := musicbotLifecycleJob(t)
	if result := handleMusicbotInstall(job); result.status != "success" {
		t.Fatalf("install failed: %s", result.errorText)
	}

	result := handleMusicbotStatus(job)
	if result.status != "success" {
		t.Fatalf("status result=%s error=%s", result.status, result.errorText)
	}
	if result.resultPayload["accepted"] != true || result.resultPayload["transport"] != "state_file" {
		t.Fatalf("status payload = %#v, want state_file fallback", result.resultPayload)
	}
}

func TestMusicbotPlaybackActionQueuesStateFileWithoutSecrets(t *testing.T) {
	t.Parallel()
	job, _, _ := musicbotLifecycleJob(t)
	job.Payload["bot_token"] = "super-secret-token"
	if result := handleMusicbotInstall(job); result.status != "success" {
		t.Fatalf("install failed: %s", result.errorText)
	}
	job.Payload["action"] = "pause"

	result := handleMusicbotPlaybackAction(job)
	if result.status != "success" {
		t.Fatalf("playback result=%s error=%s", result.status, result.errorText)
	}
	if result.resultPayload["action"] != "pause" || result.resultPayload["accepted"] != true {
		t.Fatalf("playback payload = %#v", result.resultPayload)
	}
	if strings.Contains(result.errorText, "super-secret-token") || strings.Contains(result.logText, "super-secret-token") {
		t.Fatalf("secret leaked in handler output")
	}
}

func TestMusicbotConnectionTestReturnsPlaceholderForInvalidInstallPath(t *testing.T) {
	t.Parallel()
	job, _, _ := musicbotLifecycleJob(t)
	job.Payload["install_path"] = "../invalid"
	job.Payload["platform"] = "teamspeak"

	result := handleMusicbotConnectionTest(job)
	if result.status != "success" {
		t.Fatalf("connection test result=%s error=%s", result.status, result.errorText)
	}
	if result.resultPayload["status"] != "placeholder" || result.resultPayload["platform"] != "teamspeak" {
		t.Fatalf("connection payload = %#v, want teamspeak placeholder", result.resultPayload)
	}
}
