package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"

	"github.com/pkg/sftp"
)

func generateEd25519PrivateKey() ed25519.PrivateKey {
	_, pk, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		panic(err)
	}
	return pk
}

func encodePEMPrivateKey(pk any) []byte {
	bytes, err := x509.MarshalPKCS8PrivateKey(pk)
	if err != nil {
		panic(err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: bytes})
}

func resolveUserPath(root, requested string) (string, error) {
	if strings.TrimSpace(root) == "" {
		return "", errors.New("empty root")
	}
	reqSlash := strings.ReplaceAll(requested, "\\", "/")
	if strings.Contains(reqSlash, "..") {
		return "", os.ErrPermission
	}
	rootAbs, err := filepath.Abs(root)
	if err != nil {
		return "", err
	}
	cleanReq := filepath.Clean("/" + strings.ReplaceAll(requested, "\\", "/"))
	target := filepath.Join(rootAbs, cleanReq)
	resolved, err := filepath.EvalSymlinks(filepath.Dir(target))
	if err == nil {
		target = filepath.Join(resolved, filepath.Base(target))
	}
	targetAbs, err := filepath.Abs(target)
	if err != nil {
		return "", err
	}
	if targetAbs != rootAbs && !strings.HasPrefix(strings.ToLower(targetAbs), strings.ToLower(rootAbs+string(os.PathSeparator))) {
		return "", os.ErrPermission
	}
	return targetAbs, nil
}

type jailedFileGet struct{ root string }

type jailedFilePut struct{ root string }

type jailedFileCmd struct{ root string }

type jailedFileList struct{ root string }

func (h jailedFileGet) Fileread(r *sftp.Request) (io.ReaderAt, error) {
	path, err := resolveUserPath(h.root, r.Filepath)
	if err != nil {
		return nil, os.ErrPermission
	}
	return os.Open(path)
}

func (h jailedFilePut) Filewrite(r *sftp.Request) (io.WriterAt, error) {
	path, err := resolveUserPath(h.root, r.Filepath)
	if err != nil {
		return nil, os.ErrPermission
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return nil, err
	}
	return os.OpenFile(path, os.O_CREATE|os.O_RDWR|os.O_TRUNC, 0o644)
}

func (h jailedFileCmd) Filecmd(r *sftp.Request) error {
	path, err := resolveUserPath(h.root, r.Filepath)
	if err != nil {
		return os.ErrPermission
	}
	switch r.Method {
	case "Setstat":
		return nil
	case "Rename":
		target, err := resolveUserPath(h.root, r.Target)
		if err != nil {
			return os.ErrPermission
		}
		return os.Rename(path, target)
	case "Rmdir":
		return os.Remove(path)
	case "Remove":
		return os.Remove(path)
	case "Mkdir":
		return os.MkdirAll(path, 0o755)
	default:
		return os.ErrInvalid
	}
}

func (h jailedFileList) Filelist(r *sftp.Request) (sftp.ListerAt, error) {
	path, err := resolveUserPath(h.root, r.Filepath)
	if err != nil {
		return nil, os.ErrPermission
	}
	if r.Method == "Stat" || r.Method == "Lstat" {
		st, err := os.Stat(path)
		if err != nil {
			return nil, err
		}
		return listerAt([]os.FileInfo{st}), nil
	}
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	list, err := f.Readdir(0)
	_ = f.Close()
	if err != nil {
		return nil, err
	}
	return listerAt(list), nil
}

type listerAt []os.FileInfo

func (f listerAt) ListAt(ls []os.FileInfo, offset int64) (int, error) {
	if offset >= int64(len(f)) {
		return 0, io.EOF
	}
	n := copy(ls, f[offset:])
	if n < len(ls) {
		return n, io.EOF
	}
	return n, nil
}
