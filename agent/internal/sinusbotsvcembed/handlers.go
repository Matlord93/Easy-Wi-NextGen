package sinusbotsvcembed

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type Server struct {
	cfg Config
}

type instanceMeta struct {
	InstanceID string    `json:"instance_id"`
	BotID      string    `json:"bot_id"`
	CustomerID int       `json:"customer_id"`
	Username   string    `json:"username"`
	Password   string    `json:"password"`
	Quota      int       `json:"quota"`
	Status     string    `json:"status"`
	ManageURL  string    `json:"manage_url"`
	WebPort    int       `json:"web_port"`
	CreatedAt  time.Time `json:"created_at"`
	UpdatedAt  time.Time `json:"updated_at"`
}

type createInstanceRequest struct {
	CustomerID   int    `json:"customerId"`
	Quota        int    `json:"quota"`
	Username     string `json:"username"`
	InstallDir   string `json:"installDir"`
	InstanceRoot string `json:"instanceRoot"`
	WebBindIP    string `json:"webBindIp"`
	WebPortBase  int    `json:"webPortBase"`
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/internal/sinusbot/instances", s.handleInstances)
	mux.HandleFunc("/internal/sinusbot/instances/", s.handleInstance)
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
	})
	return mux
}

func (s *Server) handleInstances(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	payload := string(body)
	if err := verifyRequestSignature(r, s.cfg, payload); err != nil {
		respondError(w, http.StatusUnauthorized, "unauthorized", err.Error())
		return
	}

	if r.Method != http.MethodPost {
		respondError(w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
		return
	}

	var req createInstanceRequest
	if err := decodeJSONBytes(body, &req); err != nil {
		respondError(w, http.StatusBadRequest, "invalid_payload", "invalid payload")
		return
	}
	if req.CustomerID <= 0 || req.Quota <= 0 {
		respondError(w, http.StatusBadRequest, "missing_fields", "customerId and quota are required")
		return
	}

	installDir := fallbackString(req.InstallDir, s.cfg.InstallDir)
	instanceRoot := fallbackString(req.InstanceRoot, s.cfg.InstanceRoot)
	webBindIP := fallbackString(req.WebBindIP, s.cfg.WebBindIP)
	webPortBase := fallbackInt(req.WebPortBase, s.cfg.WebPortBase)

	meta, err := s.createInstance(instanceRoot, installDir, webBindIP, webPortBase, req)
	if err != nil {
		respondError(w, http.StatusInternalServerError, "create_failed", err.Error())
		return
	}

	respondJSON(w, http.StatusCreated, map[string]any{
		"instanceId": meta.InstanceID,
		"botId":      meta.BotID,
		"username":   meta.Username,
		"password":   meta.Password,
		"manageUrl":  meta.ManageURL,
		"status":     meta.Status,
		"webPort":    meta.WebPort,
	})
}

func (s *Server) handleInstance(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	payload := string(body)
	if err := verifyRequestSignature(r, s.cfg, payload); err != nil {
		respondError(w, http.StatusUnauthorized, "unauthorized", err.Error())
		return
	}

	path := strings.TrimPrefix(r.URL.Path, "/internal/sinusbot/instances/")
	parts := strings.Split(strings.Trim(path, "/"), "/")
	if len(parts) == 0 || parts[0] == "" {
		respondError(w, http.StatusNotFound, "not_found", "instance not found")
		return
	}
	instanceID := parts[0]
	action := ""
	if len(parts) > 1 {
		action = parts[1]
	}

	switch r.Method {
	case http.MethodPost:
		switch action {
		case "start":
			s.handleStart(w, instanceID)
		case "stop":
			s.handleStop(w, instanceID)
		case "restart":
			s.handleRestart(w, instanceID)
		case "reset-password":
			s.handleResetPassword(w, instanceID)
		case "quota":
			s.handleQuota(w, body, instanceID)
		default:
			respondError(w, http.StatusNotFound, "not_found", "unknown action")
		}
	case http.MethodDelete:
		if action != "" {
			respondError(w, http.StatusNotFound, "not_found", "unknown action")
			return
		}
		s.handleDelete(w, instanceID)
	case http.MethodGet:
		if action == "status" {
			s.handleStatus(w, instanceID)
			return
		}
		if action == "" {
			s.handleInfo(w, instanceID)
			return
		}
		respondError(w, http.StatusNotFound, "not_found", "unknown action")
	default:
		respondError(w, http.StatusMethodNotAllowed, "method_not_allowed", "method not allowed")
	}
}

func (s *Server) handleStart(w http.ResponseWriter, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	if err := systemctlAction(instanceServiceName(instanceID), "start"); err != nil {
		meta.Status = "error"
		_ = s.saveInstance(meta)
		respondError(w, http.StatusInternalServerError, "start_failed", err.Error())
		return
	}
	meta.Status = "running"
	_ = s.saveInstance(meta)
	respondJSON(w, http.StatusOK, map[string]any{"status": meta.Status})
}

func (s *Server) handleStop(w http.ResponseWriter, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	if err := systemctlAction(instanceServiceName(instanceID), "stop"); err != nil {
		meta.Status = "error"
		_ = s.saveInstance(meta)
		respondError(w, http.StatusInternalServerError, "stop_failed", err.Error())
		return
	}
	meta.Status = "stopped"
	_ = s.saveInstance(meta)
	respondJSON(w, http.StatusOK, map[string]any{"status": meta.Status})
}

func (s *Server) handleRestart(w http.ResponseWriter, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	if err := systemctlAction(instanceServiceName(instanceID), "restart"); err != nil {
		meta.Status = "error"
		_ = s.saveInstance(meta)
		respondError(w, http.StatusInternalServerError, "restart_failed", err.Error())
		return
	}
	meta.Status = "running"
	_ = s.saveInstance(meta)
	respondJSON(w, http.StatusOK, map[string]any{"status": meta.Status})
}

func (s *Server) handleDelete(w http.ResponseWriter, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	_ = systemctlAction(instanceServiceName(instanceID), "stop")
	_ = systemctlAction(instanceServiceName(instanceID), "disable")
	unitPath := instanceServicePath(instanceID)
	if unitPath != "" {
		_ = os.Remove(unitPath)
	}
	_ = systemctlAction("", "daemon-reload")

	if err := os.RemoveAll(instanceDir(s.cfg.InstanceRoot, instanceID)); err != nil {
		respondError(w, http.StatusInternalServerError, "delete_failed", err.Error())
		return
	}

	respondJSON(w, http.StatusOK, map[string]any{"status": "deleted", "instanceId": meta.InstanceID})
}

func (s *Server) handleResetPassword(w http.ResponseWriter, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	meta.Password = randomPassword()
	meta.UpdatedAt = time.Now()
	if err := s.saveInstance(meta); err != nil {
		respondError(w, http.StatusInternalServerError, "reset_failed", err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"username": meta.Username, "password": meta.Password})
}

func (s *Server) handleQuota(w http.ResponseWriter, body []byte, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	var payload struct {
		Quota int `json:"quota"`
	}
	if err := decodeJSONBytes(body, &payload); err != nil || payload.Quota <= 0 {
		respondError(w, http.StatusBadRequest, "invalid_payload", "quota is required")
		return
	}
	meta.Quota = payload.Quota
	meta.UpdatedAt = time.Now()
	if err := s.saveInstance(meta); err != nil {
		respondError(w, http.StatusInternalServerError, "quota_failed", err.Error())
		return
	}
	respondJSON(w, http.StatusOK, map[string]any{"status": meta.Status, "quota": meta.Quota})
}

func (s *Server) handleStatus(w http.ResponseWriter, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	status := serviceStatus(instanceServiceName(instanceID))
	if status != "" {
		meta.Status = status
		_ = s.saveInstance(meta)
	}
	respondJSON(w, http.StatusOK, map[string]any{"status": meta.Status})
}

func (s *Server) handleInfo(w http.ResponseWriter, instanceID string) {
	meta, err := s.loadInstance(instanceID)
	if err != nil {
		respondError(w, http.StatusNotFound, "not_found", err.Error())
		return
	}
	if meta.BotID == "" {
		meta.BotID = meta.InstanceID
	}
	status := serviceStatus(instanceServiceName(instanceID))
	if status != "" {
		meta.Status = status
	}
	_ = s.saveInstance(meta)
	respondJSON(w, http.StatusOK, map[string]any{
		"instanceId": meta.InstanceID,
		"botId":      meta.BotID,
		"username":   meta.Username,
		"password":   meta.Password,
		"manageUrl":  meta.ManageURL,
		"status":     meta.Status,
		"webPort":    meta.WebPort,
	})
}

func (s *Server) createInstance(instanceRoot, installDir, webBindIP string, webPortBase int, req createInstanceRequest) (instanceMeta, error) {
	if !filepath.IsAbs(instanceRoot) {
		return instanceMeta{}, fmt.Errorf("instanceRoot must be an absolute path, got %q", instanceRoot)
	}
	if !filepath.IsAbs(installDir) {
		return instanceMeta{}, fmt.Errorf("installDir must be an absolute path, got %q", installDir)
	}

	binaryPath := filepath.Join(installDir, "sinusbot")
	if _, err := os.Stat(binaryPath); err != nil {
		return instanceMeta{}, fmt.Errorf("sinusbot binary not found at %s: %w", binaryPath, err)
	}

	username := req.Username
	if username == "" {
		username = fmt.Sprintf("customer-%d", req.CustomerID)
	}
	if err := validateName(username); err != nil {
		return instanceMeta{}, fmt.Errorf("invalid username: %w", err)
	}

	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		return instanceMeta{}, fmt.Errorf("create instanceRoot %s: %w", instanceRoot, err)
	}

	instanceID := randomID()
	password := randomPassword()

	port, err := nextAvailablePort(instanceRoot, webPortBase)
	if err != nil {
		return instanceMeta{}, err
	}

	instancePath := instanceDir(instanceRoot, instanceID)
	if err := os.MkdirAll(instancePath, 0o755); err != nil {
		return instanceMeta{}, fmt.Errorf("create instance dir %s: %w", instancePath, err)
	}

	dataPath := filepath.Join(instancePath, "data")
	logPath := filepath.Join(instancePath, "logs")
	if err := os.MkdirAll(dataPath, 0o755); err != nil {
		return instanceMeta{}, fmt.Errorf("create data dir: %w", err)
	}
	if err := os.MkdirAll(logPath, 0o755); err != nil {
		return instanceMeta{}, fmt.Errorf("create log dir: %w", err)
	}

	configPath := filepath.Join(instancePath, "config.ini")
	configContent := fmt.Sprintf(
		"ListenPort = %d\nListenHost = \"%s\"\nDataDir = \"%s\"\nLogFile = \"%s\"\n",
		port, webBindIP, dataPath, filepath.Join(logPath, "sinusbot.log"),
	)
	if err := os.WriteFile(configPath, []byte(configContent), 0o644); err != nil {
		return instanceMeta{}, fmt.Errorf("write config.ini: %w", err)
	}

	// chown is best-effort: the service user might not exist yet during setup.
	if s.cfg.ServiceUser != "" {
		if err := chownRecursiveToUser(instancePath, s.cfg.ServiceUser); err != nil {
			log.Printf("sinusbotsvc: chown %s to %q: %v (continuing without chown)", instancePath, s.cfg.ServiceUser, err)
		}
	}

	unitContent := systemdUnitTemplate(
		instanceServiceName(instanceID),
		s.cfg.ServiceUser,
		installDir,
		instancePath,
		fmt.Sprintf("%s --config=%s", binaryPath, configPath),
		"",
		0,
		0,
	)
	unitPath := instanceServicePath(instanceID)
	if err := os.WriteFile(unitPath, []byte(unitContent), 0o644); err != nil {
		return instanceMeta{}, fmt.Errorf("write systemd unit %s: %w", unitPath, err)
	}

	// systemd operations during creation are best-effort: we record the status
	// accurately so the panel can show "stopped" and the admin can start manually.
	instanceStatus := "running"
	if err := systemctlAction("", "daemon-reload"); err != nil {
		log.Printf("sinusbotsvc: daemon-reload: %v", err)
		instanceStatus = "stopped"
	}
	if instanceStatus == "running" {
		if err := systemctlAction(instanceServiceName(instanceID), "enable"); err != nil {
			log.Printf("sinusbotsvc: enable %s: %v", instanceID, err)
		}
		if err := systemctlAction(instanceServiceName(instanceID), "start"); err != nil {
			log.Printf("sinusbotsvc: start %s: %v (instance created with status stopped)", instanceID, err)
			instanceStatus = "stopped"
		}
	}

	manageURL := ""
	if webBindIP != "" && webBindIP != "0.0.0.0" && webBindIP != "::" {
		manageURL = fmt.Sprintf("http://%s:%d", webBindIP, port)
	}

	meta := instanceMeta{
		InstanceID: instanceID,
		BotID:      instanceID,
		CustomerID: req.CustomerID,
		Username:   username,
		Password:   password,
		Quota:      req.Quota,
		Status:     instanceStatus,
		ManageURL:  manageURL,
		WebPort:    port,
		CreatedAt:  time.Now(),
		UpdatedAt:  time.Now(),
	}
	if err := s.saveInstance(meta); err != nil {
		return instanceMeta{}, fmt.Errorf("save instance metadata: %w", err)
	}
	return meta, nil
}

func (s *Server) loadInstance(instanceID string) (instanceMeta, error) {
	path := filepath.Join(instanceDir(s.cfg.InstanceRoot, instanceID), "meta.json")
	data, err := os.ReadFile(path)
	if err != nil {
		return instanceMeta{}, errors.New("instance not found")
	}
	var meta instanceMeta
	if err := json.Unmarshal(data, &meta); err != nil {
		return instanceMeta{}, err
	}
	return meta, nil
}

func (s *Server) saveInstance(meta instanceMeta) error {
	path := filepath.Join(instanceDir(s.cfg.InstanceRoot, meta.InstanceID), "meta.json")
	meta.UpdatedAt = time.Now()
	data, err := json.MarshalIndent(meta, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0o644)
}

func instanceDir(root, instanceID string) string {
	return filepath.Join(root, instanceID)
}

func instanceServiceName(instanceID string) string {
	return fmt.Sprintf("sinusbot-%s", instanceID)
}

func instanceServicePath(instanceID string) string {
	return filepath.Join(unitDir, fmt.Sprintf("%s.service", instanceServiceName(instanceID)))
}

func nextAvailablePort(instanceRoot string, base int) (int, error) {
	used := map[int]struct{}{}
	entries, err := os.ReadDir(instanceRoot)
	if err == nil {
		for _, entry := range entries {
			if !entry.IsDir() {
				continue
			}
			metaPath := filepath.Join(instanceRoot, entry.Name(), "meta.json")
			data, err := os.ReadFile(metaPath)
			if err != nil {
				continue
			}
			var meta instanceMeta
			if err := json.Unmarshal(data, &meta); err == nil {
				used[meta.WebPort] = struct{}{}
			}
		}
	}
	for port := base; port < base+2000; port++ {
		if _, exists := used[port]; !exists && isPortFree(port) {
			return port, nil
		}
	}
	return 0, fmt.Errorf("no free port available in range %d-%d", base, base+2000)
}

// validateName ensures a name contains only safe characters to prevent path traversal.
func validateName(name string) error {
	if name == "" {
		return errors.New("name must not be empty")
	}
	for _, c := range name {
		if (c < 'a' || c > 'z') && (c < 'A' || c > 'Z') &&
			(c < '0' || c > '9') && c != '_' && c != '-' {
			return fmt.Errorf("invalid character %q: only [a-zA-Z0-9_-] allowed", c)
		}
	}
	return nil
}

func randomID() string {
	buf := make([]byte, 8)
	if _, err := rand.Read(buf); err != nil {
		return fmt.Sprintf("%d", time.Now().UnixNano())
	}
	return hex.EncodeToString(buf)
}

func randomPassword() string {
	buf := make([]byte, 12)
	if _, err := rand.Read(buf); err != nil {
		return fmt.Sprintf("pw-%d", time.Now().UnixNano())
	}
	return hex.EncodeToString(buf)
}

func fallbackString(value, fallback string) string {
	if strings.TrimSpace(value) == "" {
		return fallback
	}
	return value
}

func fallbackInt(value, fallback int) int {
	if value == 0 {
		return fallback
	}
	return value
}

func decodeJSONBytes(data []byte, out interface{}) error {
	if len(data) == 0 {
		return errors.New("empty payload")
	}
	return json.Unmarshal(data, out)
}

func respondJSON(w http.ResponseWriter, status int, payload interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	if err := json.NewEncoder(w).Encode(payload); err != nil {
		log.Printf("sinusbotsvcembed: write json response: %v", err)
	}
}

func respondError(w http.ResponseWriter, status int, code, message string) {
	respondJSON(w, status, map[string]any{
		"error_code": code,
		"error":      message,
	})
}
