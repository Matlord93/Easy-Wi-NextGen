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
	oldUser, err := kvmReadCloudFile(e.guestFile(g.ID, "user-data"))
	if err != nil {
		return nil, err
	}
	if err = kvmSafeFile(e.guestFile(g.ID, "meta-data")); err != nil {
		return nil, err
	}
	oldMeta, err := kvmReadCloudFile(e.guestFile(g.ID, "meta-data"))
	if err != nil || len(oldMeta) > 1<<20 {
		return nil, errors.New("invalid cloud-init metadata")
	}
	var meta map[string]any
	if yaml.Unmarshal(oldMeta, &meta) != nil || meta["instance-id"] != g.ID {
		return nil, errors.New("cloud-init identity changed; inspection required")
	}
	meta["local-hostname"] = host
	userData, err := yaml.Marshal(config)
	if err != nil {
		return nil, err
	}
	metaData, err := json.Marshal(meta)
	if err != nil {
		return nil, err
	}
	if err = kvmSafeFile(e.guestFile(g.ID, "network-config")); err != nil {
		return nil, err
	}
	stage, err := os.MkdirTemp(filepath.Join(e.root, "instances", g.ID), ".cloud-init-")
	if err != nil {
		return nil, err
	}
	cleanup := true
	defer func() {
		if cleanup {
			_ = os.RemoveAll(stage)
		}
	}()
	if err = os.WriteFile(filepath.Join(stage, "user-data"), append([]byte("#cloud-config\n"), userData...), 0600); err != nil {
		return nil, err
	}
	if err = os.WriteFile(filepath.Join(stage, "meta-data"), metaData, 0600); err != nil {
		return nil, err
	}
	if _, err = e.command("cloud-localds", "--network-config="+e.guestFile(g.ID, "network-config"), filepath.Join(stage, "seed.iso"), filepath.Join(stage, "user-data"), filepath.Join(stage, "meta-data")); err != nil {
		return nil, err
	}
	if err = os.Chmod(filepath.Join(stage, "seed.iso"), 0600); err != nil {
		return nil, err
	}
	seed := e.guestFile(g.ID, "seed.iso")
	if err = kvmSafeFile(seed); err != nil {
		return nil, err
	}
	if err = os.Rename(seed, filepath.Join(stage, "seed.previous")); err != nil {
		return nil, err
	}
	rollback := func(cause error) (map[string]any, error) {
		a := os.Rename(filepath.Join(stage, "seed.previous"), seed)
		b := kvmAtomicWrite(e.guestFile(g.ID, "user-data"), oldUser, 0600)
		c := kvmAtomicWrite(e.guestFile(g.ID, "meta-data"), oldMeta, 0600)
		d := kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), g)
		if a != nil || b != nil || c != nil || d != nil {
			cleanup = false
			return nil, fmt.Errorf("%w: cloud-init rollback failed; inspect host", errKVMUnconfirmed)
		}
		return nil, cause
	}
	if err = os.Rename(filepath.Join(stage, "seed.iso"), seed); err != nil {
		return rollback(err)
	}
	if err = kvmAtomicWrite(e.guestFile(g.ID, "user-data"), append([]byte("#cloud-config\n"), userData...), 0600); err != nil {
		return rollback(err)
	}
	if err = kvmAtomicWrite(e.guestFile(g.ID, "meta-data"), metaData, 0600); err != nil {
		return rollback(err)
	}
	updated := g
	updated.Hostname = host
	if err = kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), updated); err != nil {
		return rollback(err)
	}
	return map[string]any{"vm_id": g.ID, "cloud_init_updated": true}, nil
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
