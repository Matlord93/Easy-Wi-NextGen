package main

import (
	"bytes"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"time"
)

func readMinecraftConfig(dir, name string) ([]byte, error) {
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	return root.ReadFile(name)
}

// Anchoring writes to os.Root prevents customer-created symlinks from making
// the privileged agent read or overwrite files outside this instance.
func publishMinecraftFile(dir, name string, reader io.Reader, mode os.FileMode) error {
	root, err := os.OpenRoot(dir)
	if err != nil {
		return err
	}
	defer root.Close()
	parent := filepath.Dir(name)
	if err := root.MkdirAll(parent, 0755); err != nil {
		return err
	}
	var nonce [16]byte
	if _, err := rand.Read(nonce[:]); err != nil {
		return err
	}
	temporary := filepath.Join(parent, ".minecraft-"+hex.EncodeToString(nonce[:]))
	f, err := root.OpenFile(temporary, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	defer root.Remove(temporary)
	if _, err := io.Copy(f, reader); err != nil {
		f.Close()
		return err
	}
	if err := f.Chmod(mode); err != nil {
		f.Close()
		return err
	}
	info, err := root.Stat(".")
	if err != nil {
		f.Close()
		return err
	}
	uid, gid := fileOwnerIDs(info)
	if uid >= 0 {
		if err := f.Chown(uid, gid); err != nil {
			f.Close()
			return err
		}
	}
	if err := f.Sync(); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	return root.Rename(temporary, name)
}

func publishMinecraftArtifact(source, dir, name string, mode os.FileMode) error {
	f, err := os.Open(source)
	if err != nil {
		return err
	}
	defer f.Close()
	return publishMinecraftFile(dir, name, f, mode)
}

func writeManagedMinecraftConfig(dir, name string, content []byte, backup bool) (configWriteStats, error) {
	backupPath := ""
	if backup {
		previous, err := readMinecraftConfig(dir, name)
		if err != nil && !os.IsNotExist(err) {
			return configWriteStats{}, err
		}
		if err == nil {
			backupName := name + ".bak." + strconv.FormatInt(time.Now().UnixNano(), 10)
			if err := publishMinecraftFile(dir, backupName, bytes.NewReader(previous), 0640); err != nil {
				return configWriteStats{}, err
			}
			backupPath = filepath.Join(dir, backupName)
		}
	}
	if err := publishMinecraftFile(dir, name, bytes.NewReader(content), 0640); err != nil {
		return configWriteStats{}, err
	}
	sum := sha256.Sum256(content)
	return configWriteStats{Bytes: len(content), BackupPath: backupPath, Checksum: hex.EncodeToString(sum[:])}, nil
}
