package main

import (
	"encoding/json"
	"errors"
	"flag"
	"io"
	"log"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	gssh "github.com/gliderlabs/ssh"
	"github.com/pkg/sftp"
	"golang.org/x/crypto/bcrypt"
	cryptossh "golang.org/x/crypto/ssh"
)

type config struct {
	Listen      string `json:"listen"`
	HostKeyPath string `json:"hostkey_path"`
	UsersFile   string `json:"users_file"`
}

type userRecord struct {
	Username     string `json:"username"`
	PasswordHash string `json:"password_hash"`
	RootPath     string `json:"root_path"`
	Enabled      bool   `json:"enabled"`
	CreatedAt    string `json:"created_at,omitempty"`
	UpdatedAt    string `json:"updated_at,omitempty"`
}

type userStore struct {
	mu       sync.RWMutex
	users    map[string]userRecord
	usersMTS time.Time
	path     string
}

func main() {
	configPath := flag.String("config", `C:\ProgramData\EasyWI\sftp\config.json`, "path to service config")
	flag.Parse()

	cfg, err := loadConfig(*configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}
	if err := ensureHostKey(cfg.HostKeyPath); err != nil {
		log.Fatalf("host key: %v", err)
	}

	store := &userStore{users: map[string]userRecord{}, path: cfg.UsersFile}
	if err := store.reloadIfNeeded(true); err != nil {
		log.Fatalf("users load: %v", err)
	}

	go func() {
		t := time.NewTicker(10 * time.Second)
		defer t.Stop()
		for range t.C {
			if err := store.reloadIfNeeded(false); err != nil {
				log.Printf("users reload failed: %v", err)
			}
		}
	}()

	server := &gssh.Server{
		Addr: cfg.Listen,
		PasswordHandler: func(ctx gssh.Context, password string) bool {
			user, ok := store.lookup(ctx.User())
			if !ok || !user.Enabled {
				return false
			}
			return bcrypt.CompareHashAndPassword([]byte(user.PasswordHash), []byte(password)) == nil
		},
		SubsystemHandlers: map[string]gssh.SubsystemHandler{
			"sftp": func(sess gssh.Session) {
				user, ok := store.lookup(sess.User())
				if !ok || !user.Enabled {
					_ = sess.Exit(1)
					return
				}
				handlers := sftp.Handlers{
					FileGet:  jailedFileGet{root: user.RootPath},
					FilePut:  jailedFilePut{root: user.RootPath},
					FileCmd:  jailedFileCmd{root: user.RootPath},
					FileList: jailedFileList{root: user.RootPath},
				}
				reqServer := sftp.NewRequestServer(sess, handlers)
				if err := reqServer.Serve(); err != nil && !errors.Is(err, io.EOF) {
					log.Printf("sftp serve error user=%s: %v", sess.User(), err)
				}
			},
		},
		Handler: func(sess gssh.Session) {
			// no shell/exec
			_, _ = sess.Write([]byte("SFTP subsystem only\n"))
			_ = sess.Exit(1)
		},
	}

	if err := server.SetOption(gssh.HostKeyFile(cfg.HostKeyPath)); err != nil {
		log.Fatalf("host key option: %v", err)
	}
	log.Printf("easywi-sftp listening on %s", cfg.Listen)
	if err := server.ListenAndServe(); err != nil {
		log.Fatalf("listen: %v", err)
	}
}

func loadConfig(path string) (config, error) {
	cfg := config{Listen: ":2222", HostKeyPath: `C:\ProgramData\EasyWI\sftp\hostkey_ed25519`, UsersFile: `C:\ProgramData\EasyWI\sftp\users.json`}
	raw, err := os.ReadFile(path)
	if err != nil {
		return cfg, err
	}
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return cfg, err
	}
	if strings.TrimSpace(cfg.Listen) == "" {
		cfg.Listen = ":2222"
	}
	if strings.TrimSpace(cfg.HostKeyPath) == "" || strings.TrimSpace(cfg.UsersFile) == "" {
		return cfg, errors.New("invalid config")
	}
	return cfg, nil
}

func ensureHostKey(path string) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	if _, err := os.Stat(path); err == nil {
		return nil
	}
	pk, err := cryptossh.NewSignerFromKey(generateEd25519PrivateKey())
	if err != nil {
		return err
	}
	pem := encodePEMPrivateKey(pk)
	return os.WriteFile(path, pem, 0o600)
}

func (s *userStore) lookup(username string) (userRecord, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	u, ok := s.users[username]
	return u, ok
}

func (s *userStore) reloadIfNeeded(force bool) error {
	st, err := os.Stat(s.path)
	if err != nil {
		return err
	}
	if !force {
		s.mu.RLock()
		same := !st.ModTime().After(s.usersMTS)
		s.mu.RUnlock()
		if same {
			return nil
		}
	}
	raw, err := os.ReadFile(s.path)
	if err != nil {
		return err
	}
	var list []userRecord
	if err := json.Unmarshal(raw, &list); err != nil {
		return err
	}
	next := make(map[string]userRecord, len(list))
	for _, u := range list {
		next[u.Username] = u
	}
	s.mu.Lock()
	s.users = next
	s.usersMTS = st.ModTime()
	s.mu.Unlock()
	return nil
}

// helpers in util file
