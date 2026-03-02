package main

import (
	"errors"
	"io"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"testing"

	gssh "github.com/gliderlabs/ssh"
	"github.com/pkg/sftp"
	"golang.org/x/crypto/bcrypt"
	cryptossh "golang.org/x/crypto/ssh"
)

func TestResolveUserPath(t *testing.T) {
	root := t.TempDir()
	if _, err := resolveUserPath(root, "../../"); err == nil {
		t.Fatalf("expected traversal to be rejected")
	}
	if _, err := resolveUserPath(root, "/windows/system32"); err != nil {
		t.Fatalf("expected absolute windows-style path to be jailed, got %v", err)
	}
	resolved, err := resolveUserPath(root, "valid/file.txt")
	if err != nil {
		t.Fatalf("expected valid path, got %v", err)
	}
	if !filepathHasPrefix(resolved, root) {
		t.Fatalf("expected %s inside %s", resolved, root)
	}
}

func TestEmbeddedSFTPIntegration(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("posix permission model differs on windows")
	}
	root := t.TempDir()
	hash, err := bcrypt.GenerateFromPassword([]byte("pass123"), bcrypt.DefaultCost)
	if err != nil {
		t.Fatal(err)
	}
	user := userRecord{Username: "gs_1", PasswordHash: string(hash), RootPath: root, Enabled: true}

	srv := &gssh.Server{
		Addr: "127.0.0.1:0",
		PasswordHandler: func(ctx gssh.Context, password string) bool {
			return ctx.User() == user.Username && bcrypt.CompareHashAndPassword([]byte(user.PasswordHash), []byte(password)) == nil
		},
		SubsystemHandlers: map[string]gssh.SubsystemHandler{
			"sftp": func(sess gssh.Session) {
				reqServer := sftp.NewRequestServer(sess, sftp.Handlers{
					FileGet:  jailedFileGet{root: user.RootPath},
					FilePut:  jailedFilePut{root: user.RootPath},
					FileCmd:  jailedFileCmd{root: user.RootPath},
					FileList: jailedFileList{root: user.RootPath},
				})
				_ = reqServer.Serve()
			},
		},
		Handler: func(sess gssh.Session) { _, _ = io.WriteString(sess, "SFTP only") },
	}
	pk := generateEd25519PrivateKey()
	signer, err := cryptossh.NewSignerFromKey(pk)
	if err != nil {
		t.Fatal(err)
	}
	if err := srv.SetOption(gssh.HostKeyPEM(encodePEMPrivateKey(pk))); err != nil {
		t.Fatal(err)
	}
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if closeErr := ln.Close(); closeErr != nil && !errors.Is(closeErr, net.ErrClosed) {
			t.Errorf("close listener: %v", closeErr)
		}
	})
	go func() { _ = srv.Serve(ln) }()
	t.Cleanup(func() {
		if closeErr := srv.Close(); closeErr != nil {
			t.Errorf("close server: %v", closeErr)
		}
	})

	cfg := &cryptossh.ClientConfig{
		User:            "gs_1",
		Auth:            []cryptossh.AuthMethod{cryptossh.Password("pass123")},
		HostKeyCallback: cryptossh.InsecureIgnoreHostKey(),
	}
	client, err := cryptossh.Dial("tcp", ln.Addr().String(), cfg)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if closeErr := client.Close(); closeErr != nil {
			t.Errorf("close ssh client: %v", closeErr)
		}
	})

	sftpClient, err := sftp.NewClient(client)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if closeErr := sftpClient.Close(); closeErr != nil {
			t.Errorf("close sftp client: %v", closeErr)
		}
	})

	f, err := sftpClient.Create("/hello.txt")
	if err != nil {
		t.Fatal(err)
	}
	_, _ = f.Write([]byte("ok"))
	_ = f.Close()

	data, err := os.ReadFile(filepath.Join(root, "hello.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if string(data) != "ok" {
		t.Fatalf("unexpected content: %q", string(data))
	}

	if _, err := sftpClient.Stat("/../../Windows/System32"); err == nil {
		t.Fatalf("expected jailed stat to fail")
	}
	_ = signer
}

func filepathHasPrefix(path, prefix string) bool {
	p, _ := filepath.Abs(path)
	r, _ := filepath.Abs(prefix)
	return p == r || len(p) > len(r) && p[:len(r)] == r
}
