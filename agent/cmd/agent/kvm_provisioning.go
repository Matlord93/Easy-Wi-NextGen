package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"golang.org/x/crypto/ssh"
	"gopkg.in/yaml.v3"
)

func (e *kvmEngine) cloudInitView(g kvmGuest) (map[string]any, error) {
	config, err := e.readCloudInit(g)
	if err != nil {
		return nil, err
	}
	for _, raw := range config["users"].([]any) {
		if user, ok := raw.(map[string]any); ok && user["name"] == g.Username {
			return map[string]any{"hostname": g.Hostname, "username": g.Username, "ssh_keys": kvmStringList(user["ssh_authorized_keys"])}, nil
		}
	}
	return nil, errors.New("managed cloud-init user missing")
}

func (e *kvmEngine) readCloudInit(g kvmGuest) (map[string]any, error) {
	path := e.guestFile(g.ID, "user-data")
	if err := kvmSafeFile(path); err != nil {
		return nil, err
	}
	data, err := kvmReadCloudFile(path)
	if err != nil {
		return nil, err
	}
	var config map[string]any
	if yaml.Unmarshal(data, &config) != nil {
		return nil, errors.New("invalid cloud-init user-data")
	}
	if _, ok := config["users"].([]any); !ok {
		return nil, errors.New("unmanaged cloud-init users")
	}
	return config, nil
}

func (e *kvmEngine) updateCloudInit(g kvmGuest, p map[string]any) (map[string]any, error) {
	host := payloadValue(p, "hostname")
	if !kvmSlug.MatchString(host) {
		return nil, errors.New("invalid cloud-init hostname")
	}
	keys := kvmStringList(p["ssh_keys"])
	if len(keys) < 1 || len(keys) > 8 || len(strings.Join(keys, "")) > 32768 {
		return nil, errors.New("one to eight public keys required")
	}
	for i, key := range keys {
		if len(key) > 32768 {
			return nil, errors.New("invalid public key")
		}
		pub, _, options, rest, err := ssh.ParseAuthorizedKey([]byte(key))
		if err != nil || len(options) > 0 || len(strings.TrimSpace(string(rest))) > 0 {
			return nil, errors.New("invalid public key; key options are not allowed")
		}
		keys[i] = strings.TrimSpace(string(ssh.MarshalAuthorizedKey(pub)))
	}
	config, err := e.readCloudInit(g)
	if err != nil {
		return nil, err
	}
	found := false
	for _, raw := range config["users"].([]any) {
		if user, ok := raw.(map[string]any); ok && user["name"] == g.Username {
			user["ssh_authorized_keys"] = keys
			found = true
		}
	}
	if !found {
		return nil, errors.New("managed cloud-init user missing")
	}
	config["hostname"] = host
	updated := g
	updated.Hostname = host
	if err = e.replaceCloudInit(g, updated, config, nil); err != nil {
		return nil, err
	}
	return map[string]any{"vm_id": g.ID, "cloud_init_updated": true}, nil
}

func (g kvmGuest) cloudInitInstanceID() string {
	if g.CloudInitID != "" {
		return g.CloudInitID
	}
	return g.ID
}

// The caller holds the host lock and has confirmed that the VM is stopped.
func (e *kvmEngine) replaceCloudInit(old, updated kvmGuest, config map[string]any, network []byte) error {
	files := []string{"user-data", "meta-data", "network-config"}
	previous := make(map[string][]byte, len(files))
	for _, name := range files {
		data, err := kvmReadCloudFile(e.guestFile(old.ID, name))
		if err != nil {
			return err
		}
		previous[name] = data
	}
	var meta map[string]any
	if yaml.Unmarshal(previous["meta-data"], &meta) != nil || meta["instance-id"] != old.cloudInitInstanceID() {
		return errors.New("cloud-init identity changed; inspection required")
	}
	meta["instance-id"] = updated.cloudInitInstanceID()
	meta["local-hostname"] = updated.Hostname
	userData, err := yaml.Marshal(config)
	if err != nil {
		return err
	}
	metaData, err := json.Marshal(meta)
	if err != nil {
		return err
	}
	if network == nil {
		network = previous["network-config"]
	}
	next := map[string][]byte{"user-data": append([]byte("#cloud-config\n"), userData...), "meta-data": metaData, "network-config": network}
	stage, err := os.MkdirTemp(filepath.Join(e.root, "instances", old.ID), ".cloud-init-")
	if err != nil {
		return err
	}
	cleanup := true
	defer func() {
		if cleanup {
			_ = os.RemoveAll(stage)
		}
	}()
	for _, name := range files {
		if err = os.WriteFile(filepath.Join(stage, name), next[name], 0600); err != nil {
			return err
		}
	}
	newSeed := filepath.Join(stage, "seed.iso")
	if _, err = e.command("cloud-localds", "--network-config="+filepath.Join(stage, "network-config"), newSeed, filepath.Join(stage, "user-data"), filepath.Join(stage, "meta-data")); err != nil {
		return err
	}
	if err = os.Chmod(newSeed, 0600); err != nil {
		return err
	}
	if err = e.requireStopped(old.ID); err != nil {
		return err
	}
	seed := e.guestFile(old.ID, "seed.iso")
	if err = kvmSafeFile(seed); err != nil {
		return err
	}
	if err = os.Rename(seed, filepath.Join(stage, "seed.previous")); err != nil {
		return err
	}
	rollback := func(cause error) error {
		restoreErr := os.Rename(filepath.Join(stage, "seed.previous"), seed)
		for _, name := range files {
			restoreErr = errors.Join(restoreErr, kvmAtomicWrite(e.guestFile(old.ID, name), previous[name], 0600))
		}
		restoreErr = errors.Join(restoreErr, kvmWriteJSON(e.guestFile(old.ID, "manifest.json"), old))
		if restoreErr != nil {
			cleanup = false
			return fmt.Errorf("%w: cloud-init rollback failed; inspect host", errKVMUnconfirmed)
		}
		return cause
	}
	if err = os.Rename(newSeed, seed); err != nil {
		return rollback(err)
	}
	for _, name := range files {
		if err = kvmAtomicWrite(e.guestFile(old.ID, name), next[name], 0600); err != nil {
			return rollback(err)
		}
	}
	if err = kvmWriteJSON(e.guestFile(old.ID, "manifest.json"), updated); err != nil {
		return rollback(err)
	}
	return nil
}

// Keep all initialization file reads bounded, including rollback copies.
func kvmReadCloudFile(path string) ([]byte, error) {
	if err := kvmSafeFile(path); err != nil {
		return nil, err
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	data, err := io.ReadAll(io.LimitReader(f, (1<<20)+1))
	if err != nil || len(data) > 1<<20 {
		return nil, errors.New("invalid cloud-init file")
	}
	return data, nil
}
