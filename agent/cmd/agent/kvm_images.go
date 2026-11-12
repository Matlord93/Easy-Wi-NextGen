package main

import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const kvmMaxImageBytes int64 = 8 << 30

// Explicitly permitted private image mirrors must be configured by the host
// operator. Redirects and DNS results are validated independently of the URL.
func kvmImageURL(raw string) (*url.URL, error) {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || len(raw) > 2048 {
		return nil, errors.New("image URL must be HTTPS without credentials, query or fragment")
	}
	return u, nil
}
func kvmImageHostTrusted(host string) bool {
	for _, h := range strings.Split(os.Getenv("EASYWI_KVM_IMAGE_HOSTS"), ",") {
		if strings.EqualFold(strings.TrimSpace(h), host) {
			return true
		}
	}
	return false
}

var kvmNewImageClient = kvmImageClient

func kvmImageClient() *http.Client {
	dialer := &net.Dialer{Timeout: 15 * time.Second}
	tr := &http.Transport{Proxy: nil, TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}, ResponseHeaderTimeout: 30 * time.Second, DialContext: func(ctx context.Context, network, address string) (net.Conn, error) {
		host, port, err := net.SplitHostPort(address)
		if err != nil {
			return nil, err
		}
		ips, err := net.DefaultResolver.LookupIPAddr(ctx, host)
		if err != nil {
			return nil, err
		}
		for _, ip := range ips {
			if !kvmImageHostTrusted(host) && (!ip.IP.IsGlobalUnicast() || ip.IP.IsPrivate() || ip.IP.IsLoopback() || ip.IP.IsLinkLocalUnicast() || ip.IP.Equal(net.ParseIP("169.254.169.254"))) {
				return nil, errors.New("private image host requires EASYWI_KVM_IMAGE_HOSTS allowlist")
			}
		}
		for _, ip := range ips {
			conn, e := dialer.DialContext(ctx, network, net.JoinHostPort(ip.String(), port))
			if e == nil {
				return conn, nil
			}
			err = e
		}
		if err == nil {
			err = errors.New("image host has no addresses")
		}
		return nil, err
	}}
	return &http.Client{Transport: tr, Timeout: 7 * time.Minute, CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if len(via) >= 4 {
			return errors.New("too many image redirects")
		}
		_, err := kvmImageURL(req.URL.String())
		return err
	}}
}

func (e *kvmEngine) importTemplate(p map[string]any) (map[string]any, error) {
	id := payloadValue(p, "template_id")
	version := payloadValue(p, "version")
	checksum := strings.ToLower(payloadValue(p, "sha256"))
	raw := payloadValue(p, "url")
	if !kvmSlug.MatchString(id) || !kvmVersion.MatchString(version) || strings.Contains(id, "--") || strings.Contains(version, "--") || !kvmSHA.MatchString(checksum) {
		return nil, errors.New("invalid template ID, version or SHA256")
	}
	catalog := filepath.Join(e.root, "templates", id+"--"+version+".json")
	if _, err := os.Lstat(catalog); err == nil {
		return nil, errors.New("template version already exists; import a new version")
	} else if !os.IsNotExist(err) {
		return nil, err
	}
	u, err := kvmImageURL(raw)
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(e.ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return nil, err
	}
	client := kvmNewImageClient()
	defer client.CloseIdleConnections()
	res, err := client.Do(req)
	if err != nil {
		return nil, errors.New("image download failed: check HTTPS certificate, URL and host allowlist")
	}
	defer res.Body.Close()
	if res.StatusCode != 200 || res.ContentLength > kvmMaxImageBytes {
		return nil, errors.New("image download refused: unexpected HTTP status or image larger than 8 GiB")
	}
	temp, err := os.CreateTemp(filepath.Join(e.root, "images"), ".import-")
	if err != nil {
		return nil, err
	}
	defer os.Remove(temp.Name())
	hash := sha256.New()
	n, err := io.Copy(io.MultiWriter(temp, hash), io.LimitReader(res.Body, kvmMaxImageBytes+1))
	if err != nil {
		temp.Close()
		return nil, errors.New("image download interrupted")
	}
	if err = temp.Sync(); err != nil {
		temp.Close()
		return nil, err
	}
	if err = temp.Close(); err != nil {
		return nil, err
	}
	if n > kvmMaxImageBytes || hex.EncodeToString(hash.Sum(nil)) != checksum {
		return nil, errors.New("image SHA256 mismatch or download exceeds 8 GiB")
	}
	header := make([]byte, 104)
	hf, readErr := os.Open(temp.Name())
	if readErr != nil {
		return nil, readErr
	}
	_, readErr = io.ReadFull(hf, header)
	hf.Close()
	if readErr != nil {
		return nil, readErr
	}
	if len(header) < 104 || binary.BigEndian.Uint32(header[60:64]) != 0 || (binary.BigEndian.Uint32(header[4:8]) == 3 && binary.BigEndian.Uint64(header[72:80]) != 0) {
		return nil, errors.New("imported images must be clean and have no snapshots")
	}
	header = nil
	size, err := e.safeImage(temp.Name())
	if err != nil {
		return nil, err
	}
	image := filepath.Join(e.root, "images", checksum+".qcow2")
	if _, err := os.Lstat(image); os.IsNotExist(err) {
		if err = os.Rename(temp.Name(), image); err != nil {
			return nil, err
		}
	} else if err != nil {
		return nil, err
	} else {
		actual, err := e.fileHash(image)
		if err != nil || actual != checksum {
			return nil, errors.New("existing template image checksum mismatch")
		}
	}
	t := kvmTemplate{ID: id, Version: version, SHA256: checksum, VirtualSize: size, CreatedAt: kvmNow()}
	if err := kvmWriteJSON(catalog, t); err != nil {
		return nil, err
	}
	return map[string]any{"template": t}, nil
}

func (e *kvmEngine) safeImage(path string) (int64, error) {
	if err := kvmSafeFile(path); err != nil {
		return 0, err
	}
	f, err := os.Open(path)
	if err != nil {
		return 0, err
	}
	header := make([]byte, 104)
	_, err = io.ReadFull(f, header)
	f.Close()
	if err != nil {
		return 0, errors.New("invalid qcow2 header")
	}
	version := binary.BigEndian.Uint32(header[4:8])
	size := binary.BigEndian.Uint64(header[24:32])
	// Reject backing files, encryption, external data files, internal snapshots in
	// imported images, and unknown incompatible qcow2 features before qemu sees it.
	if string(header[:4]) != "QFI\xfb" || (version != 2 && version != 3) || binary.BigEndian.Uint64(header[8:16]) != 0 || binary.BigEndian.Uint32(header[16:20]) != 0 || binary.BigEndian.Uint32(header[32:36]) != 0 || size < 1 || size > 2<<40 {
		return 0, errors.New("only standalone unencrypted qcow2 images up to 2 TiB are allowed")
	}
	if version == 3 && binary.BigEndian.Uint64(header[72:80]) & ^uint64(1) != 0 {
		return 0, errors.New("qcow2 incompatible features are not allowed")
	}
	out, err := e.command("qemu-img", "info", "--force-share", "--output=json", "-f", "qcow2", path)
	if err != nil {
		return 0, err
	}
	var info struct {
		Format  string `json:"format"`
		Backing string `json:"backing-filename"`
		Size    int64  `json:"virtual-size"`
	}
	if json.Unmarshal(out, &info) != nil || info.Format != "qcow2" || info.Backing != "" || info.Size != int64(size) {
		return 0, errors.New("qcow2 image metadata mismatch")
	}
	return int64(size), nil
}
func (e *kvmEngine) fileHash(path string) (string, error) {
	if err := kvmSafeFile(path); err != nil {
		return "", err
	}
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	buf := make([]byte, 1<<20)
	for {
		if err := e.ctx.Err(); err != nil {
			return "", err
		}
		n, err := f.Read(buf)
		if n > 0 {
			h.Write(buf[:n])
		}
		if err == io.EOF {
			break
		}
		if err != nil {
			return "", err
		}
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func (e *kvmEngine) readBackup(vm, id string) (kvmBackup, error) {
	var b kvmBackup
	if !kvmUUID.MatchString(id) {
		return b, errors.New("invalid backup UUID")
	}
	err := kvmReadJSON(filepath.Join(e.root, "backups", vm, id, "manifest.json"), &b)
	if err != nil || b.ID != id || b.VM != vm || !kvmSHA.MatchString(b.SHA256) {
		return b, errors.New("backup not found or invalid manifest")
	}
	return b, nil
}
func (e *kvmEngine) backup(g kvmGuest, p map[string]any) (map[string]any, error) {
	id := payloadValue(p, "operation_id")
	parent := filepath.Join(e.root, "backups", g.ID)
	if err := os.MkdirAll(parent, 0700); err != nil {
		return nil, err
	}
	if err := kvmSafeFile(parent); err != nil {
		return nil, err
	}
	destination := filepath.Join(parent, id)
	if _, err := os.Lstat(destination); !os.IsNotExist(err) {
		return nil, errors.New("backup destination already exists")
	}
	stage, err := os.MkdirTemp(parent, ".backup-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(stage)
	image := filepath.Join(stage, "disk.qcow2")
	if _, err := e.command("qemu-img", "convert", "-f", "qcow2", "-O", "qcow2", e.guestFile(g.ID, "disk.qcow2"), image); err != nil {
		return nil, err
	}
	if _, err := e.safeImage(image); err != nil {
		return nil, err
	}
	hash, err := e.fileHash(image)
	if err != nil {
		return nil, err
	}
	info, err := os.Stat(image)
	if err != nil {
		return nil, err
	}
	b := kvmBackup{ID: id, VM: g.ID, SHA256: hash, Size: info.Size(), CreatedAt: kvmNow()}
	if err := kvmWriteJSON(filepath.Join(stage, "manifest.json"), b); err != nil {
		return nil, err
	}
	if err := os.Rename(stage, destination); err != nil {
		return nil, err
	}
	return map[string]any{"backup": b}, nil
}
func (e *kvmEngine) restore(g kvmGuest, p map[string]any) (map[string]any, error) {
	b, err := e.readBackup(g.ID, payloadValue(p, "backup_id"))
	if err != nil {
		return nil, err
	}
	snaps, err := e.virsh("snapshot-list", g.ID, "--name")
	if err != nil {
		return nil, err
	}
	if len(nonEmptyLines(string(snaps))) > 0 {
		return nil, errors.New("delete VM snapshots before restoring a backup")
	}
	source := filepath.Join(e.root, "backups", g.ID, b.ID, "disk.qcow2")
	hash, err := e.fileHash(source)
	if err != nil || hash != b.SHA256 {
		return nil, errors.New("backup checksum mismatch; original VM disk retained")
	}
	if _, err := e.safeImage(source); err != nil {
		return nil, err
	}
	stage := e.guestFile(g.ID, "restore-"+payloadValue(p, "operation_id")+".qcow2")
	defer os.Remove(stage)
	if _, err := os.Lstat(stage); !os.IsNotExist(err) {
		return nil, errors.New("restore staging path already exists")
	}
	if _, err := e.command("qemu-img", "convert", "-f", "qcow2", "-O", "qcow2", source, stage); err != nil {
		return nil, err
	}
	if _, err := e.command("qemu-img", "resize", "-f", "qcow2", stage, fmt.Sprintf("%dG", g.DiskGiB)); err != nil {
		return nil, err
	}
	disk := e.guestFile(g.ID, "disk.qcow2")
	previous := e.guestFile(g.ID, "before-restore-"+payloadValue(p, "operation_id")+".qcow2")
	if _, err := os.Lstat(previous); !os.IsNotExist(err) {
		return nil, errors.New("restore rollback disk already exists")
	}
	if err := os.Rename(disk, previous); err != nil {
		return nil, err
	}
	if err := os.Rename(stage, disk); err != nil {
		_ = os.Rename(previous, disk)
		return nil, err
	}
	if err := e.define(g); err != nil {
		_ = os.Rename(disk, stage)
		if rollback := os.Rename(previous, disk); rollback != nil {
			return nil, errors.New("restore failed and disk rollback requires operator attention")
		}
		return nil, err
	}
	return map[string]any{"vm_id": g.ID, "backup_id": b.ID, "previous_disk_retained": true}, nil
}
