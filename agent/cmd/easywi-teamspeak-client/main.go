package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"log"
	"os"
)

func main() {
	// Verify the expected invocation environment.
	// The bridge injects exactly one of these env vars when it spawns us.
	clientLib := os.Getenv("EASYWI_TS_CLIENT_LIB")
	nativeSDK := os.Getenv("EASYWI_TS_NATIVE_SDK")
	if clientLib != "1" && nativeSDK != "1" {
		fmt.Fprintln(os.Stderr,
			"[easywi-teamspeak-client] fatal: must be invoked by easywi-teamspeak-bridge "+
				"(EASYWI_TS_CLIENT_LIB=1 or EASYWI_TS_NATIVE_SDK=1 not set)")
		os.Exit(1)
	}

	logger := log.New(os.Stderr, "[easywi-teamspeak-client] ", log.LstdFlags)
	backend := newBackend(logger)
	h := newHandler(backend, logger)

	logger.Printf("started backend=%s (client_lib=%s native_sdk=%s)",
		backend.Name(), clientLib, nativeSDK)

	enc := json.NewEncoder(os.Stdout)
	enc.SetEscapeHTML(false)

	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		var req request
		if err := json.Unmarshal(scanner.Bytes(), &req); err != nil {
			_ = enc.Encode(response{OK: false, Error: "invalid JSON request"})
			continue
		}
		_ = enc.Encode(h.dispatch(req))
	}

	if err := scanner.Err(); err != nil {
		logger.Printf("stdin read error: %v", err)
		os.Exit(1)
	}
	logger.Printf("exiting (stdin closed)")
}
