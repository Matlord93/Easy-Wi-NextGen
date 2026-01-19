package main

import (
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"text/template"
)

type gameServer struct {
	config    gamesvcConfig
	processes map[string]*exec.Cmd
	mu        sync.Mutex
}

func newGameServer(cfg gamesvcConfig) *gameServer {
	return &gameServer{
		config:    cfg,
		processes: map[string]*exec.Cmd{},
	}
}

func (s *gameServer) routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/ports/check-free", s.handleCheckFree)
	mux.HandleFunc("/instance/render-config", s.handleRenderConfig)
	mux.HandleFunc("/instance/start", s.handleStartInstance)
	mux.HandleFunc("/instance/stop", s.handleStopInstance)
	return mux
}

type checkFreeRequest struct {
	Checks []portCheck `json:"checks"`
}

type portCheck struct {
	Proto string `json:"proto"`
	Port  int    `json:"port"`
}

type checkFreeResponse struct {
	Results []portCheckResult `json:"results"`
}

type portCheckResult struct {
	Proto string `json:"proto"`
	Port  int    `json:"port"`
	Free  bool   `json:"free"`
	Error string `json:"error,omitempty"`
}

func (s *gameServer) handleCheckFree(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		return
	}
	var req checkFreeRequest
	if err := readJSON(r.Body, &req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"error": "invalid payload"})
		return
	}
	results := make([]portCheckResult, 0, len(req.Checks))
	for _, check := range req.Checks {
		result := portCheckResult{Proto: strings.ToLower(check.Proto), Port: check.Port}
		if check.Port <= 0 || check.Port > 65535 {
			result.Error = "invalid port"
			results = append(results, result)
			continue
		}
		free, err := checkPortFree(result.Proto, check.Port)
		if err != nil {
			result.Error = err.Error()
			results = append(results, result)
			continue
		}
		result.Free = free
		results = append(results, result)
	}
	writeJSON(w, http.StatusOK, checkFreeResponse{Results: results})
}

func checkPortFree(proto string, port int) (bool, error) {
	address := net.JoinHostPort("0.0.0.0", fmtPort(port))
	if proto == "udp" {
		conn, err := net.ListenPacket("udp", address)
		if err != nil {
			return false, nil
		}
		_ = conn.Close()
		return true, nil
	}
	if proto == "tcp" {
		listener, err := net.Listen("tcp", address)
		if err != nil {
			return false, nil
		}
		_ = listener.Close()
		return true, nil
	}
	return false, errors.New("unsupported proto")
}

type renderConfigRequest struct {
	InstanceID  string            `json:"instance_id"`
	TemplateDir string            `json:"template_dir"`
	OutputDir   string            `json:"output_dir"`
	Files       []renderFile      `json:"files"`
	Values      map[string]string `json:"values"`
}

type renderFile struct {
	Template string `json:"template"`
	Target   string `json:"target"`
}

func (s *gameServer) handleRenderConfig(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		return
	}
	var req renderConfigRequest
	if err := readJSON(r.Body, &req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "invalid payload"})
		return
	}
	if req.InstanceID == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "instance_id is required"})
		return
	}
	baseTemplateDir := s.config.TemplateDir
	if req.TemplateDir != "" {
		baseTemplateDir = req.TemplateDir
	}
	baseOutputDir := filepath.Join(s.config.BaseDir, req.InstanceID)
	if req.OutputDir != "" {
		baseOutputDir = req.OutputDir
	}

	for _, file := range req.Files {
		templatePath, err := safeJoin(baseTemplateDir, file.Template)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": err.Error()})
			return
		}
		outputPath, err := safeJoin(baseOutputDir, file.Target)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": err.Error()})
			return
		}
		if err := renderTemplateFile(templatePath, outputPath, req.Values); err != nil {
			writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
			return
		}
	}

	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func renderTemplateFile(templatePath, outputPath string, values map[string]string) error {
	content, err := os.ReadFile(templatePath)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(outputPath), 0750); err != nil {
		return err
	}
	parsed, err := template.New(filepath.Base(templatePath)).Option("missingkey=error").Parse(string(content))
	if err != nil {
		return err
	}
	file, err := os.Create(outputPath)
	if err != nil {
		return err
	}
	defer func() {
		_ = file.Close()
	}()
	return parsed.Execute(file, values)
}

func safeJoin(baseDir, relative string) (string, error) {
	if relative == "" {
		return "", errors.New("path is required")
	}
	clean := filepath.Clean(relative)
	if strings.Contains(clean, "..") {
		return "", errors.New("path traversal detected")
	}
	joined := filepath.Join(baseDir, clean)
	if !strings.HasPrefix(joined, filepath.Clean(baseDir)+string(os.PathSeparator)) && filepath.Clean(joined) != filepath.Clean(baseDir) {
		return "", errors.New("invalid path")
	}
	return joined, nil
}

type startInstanceRequest struct {
	InstanceID string            `json:"instance_id"`
	Command    string            `json:"command"`
	Args       []string          `json:"args"`
	Env        map[string]string `json:"env"`
	WorkDir    string            `json:"work_dir"`
}

func (s *gameServer) handleStartInstance(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		return
	}
	var req startInstanceRequest
	if err := readJSON(r.Body, &req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "invalid payload"})
		return
	}
	if req.InstanceID == "" || req.Command == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "instance_id and command are required"})
		return
	}

	s.mu.Lock()
	if _, exists := s.processes[req.InstanceID]; exists {
		s.mu.Unlock()
		writeJSON(w, http.StatusConflict, map[string]any{"ok": false, "error": "instance already running"})
		return
	}
	s.mu.Unlock()

	cmd := exec.Command(req.Command, req.Args...)
	if req.WorkDir != "" {
		cmd.Dir = req.WorkDir
	}
	if len(req.Env) > 0 {
		env := os.Environ()
		for key, value := range req.Env {
			env = append(env, key+"="+value)
		}
		cmd.Env = env
	}

	if err := cmd.Start(); err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]any{"ok": false, "error": err.Error()})
		return
	}

	s.mu.Lock()
	s.processes[req.InstanceID] = cmd
	s.mu.Unlock()

	go func() {
		err := cmd.Wait()
		s.mu.Lock()
		delete(s.processes, req.InstanceID)
		s.mu.Unlock()
		if err != nil {
			log.Printf("instance %s exited: %v", req.InstanceID, err)
		}
	}()

	writeJSON(w, http.StatusOK, map[string]any{"ok": true, "pid": cmd.Process.Pid})
}

type stopInstanceRequest struct {
	InstanceID string `json:"instance_id"`
}

func (s *gameServer) handleStopInstance(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		w.WriteHeader(http.StatusMethodNotAllowed)
		return
	}
	var req stopInstanceRequest
	if err := readJSON(r.Body, &req); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "invalid payload"})
		return
	}
	if req.InstanceID == "" {
		writeJSON(w, http.StatusBadRequest, map[string]any{"ok": false, "error": "instance_id is required"})
		return
	}

	s.mu.Lock()
	cmd, exists := s.processes[req.InstanceID]
	if !exists {
		s.mu.Unlock()
		writeJSON(w, http.StatusNotFound, map[string]any{"ok": false, "error": "instance not running"})
		return
	}
	delete(s.processes, req.InstanceID)
	s.mu.Unlock()

	if cmd.Process != nil {
		_ = cmd.Process.Kill()
	}

	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func readJSON(body io.ReadCloser, out any) error {
	defer func() {
		_ = body.Close()
	}()
	decoder := json.NewDecoder(body)
	decoder.DisallowUnknownFields()
	return decoder.Decode(out)
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	encoder := json.NewEncoder(w)
	encoder.SetIndent("", "  ")
	_ = encoder.Encode(payload)
}

func fmtPort(port int) string {
	return strconv.Itoa(port)
}

func init() {
	log.SetFlags(log.LstdFlags | log.Lshortfile)
}
