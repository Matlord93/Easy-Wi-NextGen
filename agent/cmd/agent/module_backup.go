package main

import (
	"archive/tar"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"easywi/agent/internal/fileapi"
)

const moduleDescriptorLimit int64 = 64 * 1024

// The descriptor is supplied by the authenticated control plane; archived paths
// never select restore destinations. Roots are also restricted locally by the
// agent administrator, independently of control-plane configuration.
type moduleBackupDescriptor struct {
	Module          string                `json:"module"`
	ResourceID      string                `json:"resource_id"`
	Path            string                `json:"path,omitempty"`
	AdditionalPaths map[string]string     `json:"additional_paths,omitempty"`
	Service         string                `json:"service,omitempty"`
	Services        []string              `json:"services,omitempty"`
	Database        *moduleBackupDatabase `json:"database,omitempty"`
	Exclude         []string              `json:"exclude,omitempty"`
}
type moduleBackupDatabase struct {
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Password string `json:"password"`
	TLSMode  string `json:"tls_mode"`
	CACert   string `json:"ca_cert,omitempty"`
}
type moduleBackupRequest struct {
	Descriptor moduleBackupDescriptor `json:"descriptor"`
	CustomerID string                 `json:"customer_id"`
	DryRun     bool                   `json:"dry_run,omitempty"`
}
type moduleBackupHandler struct {
	auth      fileapi.Config
	roots     map[string][]string
	maxBytes  int64
	timeout   time.Duration
	configErr error
	run       moduleCommandRunner
}
type moduleBackupRoot struct{ Name, Path string }

var moduleLogicalName = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$`)
var moduleServiceName = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.@:-]{0,254}$`)

type moduleResourceLock struct {
	token      chan struct{}
	references int
}

var moduleOperationLocks = struct {
	sync.Mutex
	entries map[string]*moduleResourceLock
}{entries: make(map[string]*moduleResourceLock)}

func newModuleBackupHandler(auth fileapi.Config) *moduleBackupHandler {
	h := &moduleBackupHandler{auth: auth, maxBytes: 100 * 1024 * 1024 * 1024, timeout: 2 * time.Hour, run: runModuleCommand}
	h.roots = map[string][]string{
		"game":     {"/home", "/opt/easywi", "/var/lib/easywi"},
		"voice":    {"/home", "/opt/easywi", "/var/lib/easywi"},
		"mail":     {"/var/vmail", "/var/lib/easywi", "/opt/easywi"},
		"musicbot": {"/home", "/opt/easywi", "/var/lib/easywi"},
		"db":       {},
	}
	if raw := os.Getenv("EASYWI_MODULE_BACKUP_ROOTS"); raw != "" {
		if err := json.Unmarshal([]byte(raw), &h.roots); err != nil {
			h.configErr = errors.New("invalid EASYWI_MODULE_BACKUP_ROOTS")
		}
	}
	for _, roots := range h.roots {
		for _, root := range roots {
			if !filepath.IsAbs(root) || filepath.Clean(root) == "/" {
				h.configErr = errors.New("backup allowroots must be explicit absolute directories")
			}
		}
	}
	if raw := os.Getenv("EASYWI_MODULE_BACKUP_MAX_BYTES"); raw != "" {
		n, err := strconv.ParseInt(raw, 10, 64)
		if err != nil || n <= 0 || n > 1<<60 {
			h.configErr = errors.New("invalid EASYWI_MODULE_BACKUP_MAX_BYTES")
		} else {
			h.maxBytes = n
		}
	}
	if raw := os.Getenv("EASYWI_MODULE_BACKUP_TIMEOUT"); raw != "" {
		d, err := time.ParseDuration(raw)
		if err != nil || d <= 0 || d > 24*time.Hour {
			h.configErr = errors.New("invalid EASYWI_MODULE_BACKUP_TIMEOUT")
		} else {
			h.timeout = d
		}
	}
	return h
}

func (h *moduleBackupHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		w.Header().Set("Allow", "POST")
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	if r.URL.Path != "/v1/module-backups/snapshot" && r.URL.Path != "/v1/module-backups/restore" {
		http.NotFound(w, r)
		return
	}
	customer, digest, err := fileapi.VerifyRequestSignatureHeaders(r, h.auth)
	if err != nil {
		http.Error(w, "invalid request authentication", http.StatusUnauthorized)
		return
	}
	if h.configErr != nil {
		http.Error(w, h.configErr.Error(), http.StatusServiceUnavailable)
		return
	}
	deadline := time.Now().Add(h.timeout)
	controller := http.NewResponseController(w)
	_ = controller.SetReadDeadline(deadline)
	_ = controller.SetWriteDeadline(deadline)
	ctx, cancel := context.WithDeadline(r.Context(), deadline)
	defer cancel()
	scratch, err := os.MkdirTemp("", "easywi-module-backup-")
	if err != nil {
		http.Error(w, "create private staging directory", http.StatusInternalServerError)
		return
	}
	defer func() {
		if err := os.RemoveAll(scratch); err != nil {
			log.Printf("module backup: remove staging directory: %v", err)
		}
	}()
	var request moduleBackupRequest
	archivePath := filepath.Join(scratch, "archive.tar.gz")
	if r.URL.Path == "/v1/module-backups/snapshot" {
		body, readErr := io.ReadAll(io.LimitReader(r.Body, moduleDescriptorLimit+1))
		if readErr != nil || int64(len(body)) > moduleDescriptorLimit {
			http.Error(w, "descriptor too large or unreadable", http.StatusBadRequest)
			return
		}
		sum := sha256.Sum256(body)
		if !hmac.Equal([]byte(hex.EncodeToString(sum[:])), []byte(digest)) {
			http.Error(w, "invalid body hash", http.StatusUnauthorized)
			return
		}
		err = decodeModuleDescriptor(body, &request)
	} else {
		envelope := filepath.Join(scratch, "envelope.tar")
		err = spoolModuleBody(ctx, r.Body, envelope, digest, h.maxBytes+1024*1024)
		if err == nil {
			err = readModuleEnvelope(envelope, archivePath, &request, h.maxBytes)
		}
	}
	if err != nil {
		moduleBackupResponse(w, http.StatusBadRequest, false, false, err.Error())
		return
	}
	if customer != request.CustomerID {
		moduleBackupResponse(w, http.StatusForbidden, false, request.DryRun, "customer does not match signature")
		return
	}
	roots, services, err := h.validate(request)
	if err != nil {
		moduleBackupResponse(w, http.StatusBadRequest, false, request.DryRun, err.Error())
		return
	}
	unlock, err := lockModuleOperation(ctx, request, roots)
	if err != nil {
		moduleBackupResponse(w, http.StatusRequestTimeout, false, request.DryRun, "operation cancelled while waiting for resource")
		return
	}
	defer unlock()
	if r.URL.Path == "/v1/module-backups/snapshot" {
		if request.DryRun {
			moduleBackupResponse(w, http.StatusBadRequest, false, true, "snapshot does not support dry_run")
			return
		}
		err = h.snapshot(ctx, request, roots, services, archivePath, scratch)
		if err != nil {
			moduleBackupResponse(w, http.StatusInternalServerError, false, false, err.Error())
			return
		}
		digest, size, hashErr := computeFileChecksumAndSize(archivePath)
		if hashErr != nil {
			moduleBackupResponse(w, http.StatusInternalServerError, false, false, "read completed archive")
			return
		}
		f, openErr := os.Open(archivePath)
		if openErr != nil {
			moduleBackupResponse(w, http.StatusInternalServerError, false, false, "open completed archive")
			return
		}
		defer func() {
			if err := f.Close(); err != nil {
				log.Printf("module backup: close streamed archive: %v", err)
			}
		}()
		w.Header().Set("Content-Type", "application/gzip")
		w.Header().Set("Content-Length", strconv.FormatInt(size, 10))
		w.Header().Set("X-Archive-SHA256", digest)
		h.signResponse(w, r, customer, http.StatusOK, digest)
		_, _ = io.Copy(w, f)
		return
	}
	err = h.restore(ctx, request, roots, services, archivePath, scratch)
	if err != nil {
		moduleBackupResponse(w, http.StatusInternalServerError, false, request.DryRun, err.Error())
		return
	}
	message := "restore completed"
	if request.DryRun {
		message = "archive and restore prerequisites validated; no live data changed"
	}
	body, marshalErr := json.Marshal(struct {
		Success bool   `json:"success"`
		DryRun  bool   `json:"dry_run"`
		Message string `json:"message"`
	}{true, request.DryRun, message})
	if marshalErr != nil {
		moduleBackupResponse(w, http.StatusInternalServerError, false, request.DryRun, "encode restore response")
		return
	}
	responseHash := sha256.Sum256(body)
	h.signResponse(w, r, customer, http.StatusOK, hex.EncodeToString(responseHash[:]))
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Content-Length", strconv.Itoa(len(body)))
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write(body)
}

func (h *moduleBackupHandler) signResponse(w http.ResponseWriter, r *http.Request, customer string, status int, digest string) {
	payload := strings.Join([]string{h.auth.AgentID, customer, strings.ToUpper(r.Method), r.URL.RequestURI(), r.Header.Get("X-Timestamp"), strings.ToLower(r.Header.Get("X-Content-SHA256")), strconv.Itoa(status), digest}, "\n")
	mac := hmac.New(sha256.New, []byte(h.auth.Secret))
	_, _ = mac.Write([]byte(payload))
	w.Header().Set("X-Response-SHA256", digest)
	w.Header().Set("X-Operation-Signature", hex.EncodeToString(mac.Sum(nil)))
}

func moduleBackupResponse(w http.ResponseWriter, status int, success, dryRun bool, message string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(struct {
		Success bool   `json:"success"`
		DryRun  bool   `json:"dry_run"`
		Message string `json:"message"`
	}{success, dryRun, message})
}
func decodeModuleDescriptor(body []byte, request *moduleBackupRequest) error {
	decoder := json.NewDecoder(strings.NewReader(string(body)))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(request); err != nil {
		return errors.New("invalid module descriptor")
	}
	if err := decoder.Decode(new(any)); err != io.EOF {
		return errors.New("trailing descriptor data")
	}
	return nil
}
func spoolModuleBody(ctx context.Context, body io.Reader, destination, digest string, maxBytes int64) error {
	f, err := os.OpenFile(destination, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	hash := sha256.New()
	n, copyErr := io.Copy(io.MultiWriter(f, hash), io.LimitReader(moduleContextReader{ctx, body}, maxBytes+1))
	closeErr := f.Close()
	if copyErr != nil {
		return errors.New("incomplete archive upload")
	}
	if closeErr != nil {
		return closeErr
	}
	if n > maxBytes {
		return errors.New("archive upload exceeds configured limit")
	}
	if !hmac.Equal([]byte(hex.EncodeToString(hash.Sum(nil))), []byte(digest)) {
		return errors.New("invalid body hash")
	}
	return nil
}

type moduleContextReader struct {
	ctx context.Context
	r   io.Reader
}

func (r moduleContextReader) Read(p []byte) (int, error) {
	if err := r.ctx.Err(); err != nil {
		return 0, err
	}
	return r.r.Read(p)
}
func readModuleEnvelope(envelope, archivePath string, request *moduleBackupRequest, maxBytes int64) (result error) {
	f, err := os.Open(envelope)
	if err != nil {
		return err
	}
	defer func() {
		if closeErr := f.Close(); result == nil {
			result = closeErr
		}
	}()
	tr := tar.NewReader(f)
	seen := map[string]bool{}
	for {
		header, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return errors.New("invalid restore envelope")
		}
		if (header.Typeflag != tar.TypeReg && header.Typeflag != tarTypeLegacyRegular) || seen[header.Name] {
			return errors.New("invalid or duplicate envelope entry")
		}
		seen[header.Name] = true
		switch header.Name {
		case "descriptor.json":
			if header.Size < 0 || header.Size > moduleDescriptorLimit {
				return errors.New("descriptor exceeds size limit")
			}
			body, readErr := io.ReadAll(tr)
			if readErr != nil {
				return readErr
			}
			if err = decodeModuleDescriptor(body, request); err != nil {
				return err
			}
		case "archive.tar.gz":
			if header.Size <= 0 || header.Size > maxBytes {
				return errors.New("archive exceeds size limit")
			}
			out, openErr := os.OpenFile(archivePath, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
			if openErr != nil {
				return openErr
			}
			_, err = io.Copy(out, tr)
			closeErr := out.Close()
			if err != nil {
				return err
			}
			if closeErr != nil {
				return closeErr
			}
		default:
			return errors.New("unexpected envelope entry")
		}
	}
	if !seen["descriptor.json"] || !seen["archive.tar.gz"] {
		return errors.New("incomplete restore envelope")
	}
	// Tar end padding must not hide a second archive or unsigned application data.
	rest, err := io.ReadAll(io.LimitReader(f, 1024*1024+1))
	if err != nil || len(rest) > 1024*1024 {
		return errors.New("invalid envelope trailer")
	}
	for _, b := range rest {
		if b != 0 {
			return errors.New("unexpected envelope trailer")
		}
	}
	return nil
}

func (h *moduleBackupHandler) validate(r moduleBackupRequest) ([]moduleBackupRoot, []string, error) {
	d := r.Descriptor
	switch d.Module {
	case "game", "mail", "voice", "db", "musicbot":
	default:
		return nil, nil, errors.New("unsupported module")
	}
	if r.CustomerID == "" || len(r.CustomerID) > 128 || strings.ContainsAny(r.CustomerID, "\x00\r\n") || d.ResourceID == "" || len(d.ResourceID) > 192 || strings.ContainsAny(d.ResourceID, "\x00\r\n") {
		return nil, nil, errors.New("invalid customer or resource identifier")
	}
	var roots []moduleBackupRoot
	if d.Path != "" {
		roots = append(roots, moduleBackupRoot{"primary", d.Path})
	}
	if len(d.AdditionalPaths) > 32 {
		return nil, nil, errors.New("too many additional roots")
	}
	for name, path := range d.AdditionalPaths {
		if name == "primary" || !moduleLogicalName.MatchString(name) {
			return nil, nil, errors.New("invalid additional root name")
		}
		roots = append(roots, moduleBackupRoot{name, path})
	}
	sort.Slice(roots, func(i, j int) bool { return roots[i].Name < roots[j].Name })
	for i, root := range roots {
		if !filepath.IsAbs(root.Path) || filepath.Clean(root.Path) != root.Path || root.Path == "/" {
			return nil, nil, errors.New("root must be a clean absolute path")
		}
		allowed := false
		for _, base := range h.roots[d.Module] {
			if modulePathWithin(base, root.Path) {
				allowed = true
				break
			}
		}
		if !allowed {
			return nil, nil, fmt.Errorf("root %s is outside configured module allowroots", root.Name)
		}
		if err := moduleNoSymlinkAncestors(root.Path); err != nil {
			return nil, nil, err
		}
		// Parents must already exist: do not create uncontrolled directory trees.
		parent, err := os.Stat(filepath.Dir(root.Path))
		if err != nil || !parent.IsDir() {
			return nil, nil, fmt.Errorf("root %s parent directory is missing", root.Name)
		}
		for j := 0; j < i; j++ {
			if modulePathWithin(roots[j].Path, root.Path) || modulePathWithin(root.Path, roots[j].Path) {
				return nil, nil, errors.New("overlapping resource roots")
			}
		}
	}
	if len(roots) == 0 && d.Database == nil {
		return nil, nil, errors.New("descriptor needs filesystem roots or a database")
	}
	if d.Module == "db" && (d.Database == nil || len(roots) != 0) {
		return nil, nil, errors.New("db module requires only a database descriptor")
	}
	if d.Module != "db" && d.Path == "" {
		return nil, nil, errors.New("filesystem module requires its primary root")
	}
	if len(d.Exclude) > 128 {
		return nil, nil, errors.New("too many exclusions")
	}
	for _, exclude := range d.Exclude {
		if !moduleSafeRelative(exclude) || exclude == "." {
			return nil, nil, errors.New("unsafe archive exclusion")
		}
	}
	services := moduleServiceNames(d)
	if len(services) > 16 {
		return nil, nil, errors.New("too many resource services")
	}
	for _, service := range services {
		if !moduleServiceName.MatchString(service) || strings.HasPrefix(service, "-") {
			return nil, nil, errors.New("invalid resource service")
		}
	}
	if (d.Module == "voice" || d.Module == "game" || d.Module == "mail") && len(services) == 0 {
		return nil, nil, errors.New("consistent backups require explicit resource services")
	}
	if d.Database != nil {
		if err := validateModuleDatabase(d.Database); err != nil {
			return nil, nil, err
		}
	}
	return roots, services, nil
}

// systemctl treats an omitted suffix as .service. Normalize before both
// lifecycle operations and lock acquisition so aliases cannot bypass the shared
// unit lock or deadlock by acquiring the same unit twice.
func moduleServiceNames(d moduleBackupDescriptor) []string {
	services := append([]string{}, d.Services...)
	if d.Service != "" {
		services = append(services, d.Service)
	}
	for i, service := range services {
		if !strings.HasSuffix(service, ".service") {
			services[i] = service + ".service"
		}
	}
	sort.Strings(services)
	return compactModuleStrings(services)
}

func compactModuleStrings(in []string) []string {
	out := in[:0]
	for _, s := range in {
		if len(out) == 0 || out[len(out)-1] != s {
			out = append(out, s)
		}
	}
	return out
}
func modulePathWithin(base, target string) bool {
	relative, err := filepath.Rel(filepath.Clean(base), target)
	return err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(filepath.Separator)) && !filepath.IsAbs(relative)
}
func moduleNoSymlinkAncestors(target string) error {
	for current := target; ; current = filepath.Dir(current) {
		info, err := os.Lstat(current)
		if err != nil && !os.IsNotExist(err) {
			return err
		}
		if err == nil && (info.Mode()&os.ModeSymlink != 0) {
			return errors.New("resource path contains a symbolic link")
		}
		if current == filepath.Dir(current) {
			break
		}
	}
	return nil
}
func moduleSafeRelative(name string) bool {
	return name != "" && !strings.ContainsAny(name, "\\\x00") && !strings.HasPrefix(name, "/") && filepath.ToSlash(filepath.Clean(name)) == name && name != ".." && !strings.HasPrefix(name, "../")
}
func lockModuleOperation(ctx context.Context, r moduleBackupRequest, roots []moduleBackupRoot) (func(), error) {
	keys := []string{"resource:" + r.Descriptor.Module + ":" + r.CustomerID + ":" + r.Descriptor.ResourceID}
	for _, root := range roots {
		keys = append(keys, "path:"+root.Path)
	}
	if db := r.Descriptor.Database; db != nil {
		keys = append(keys, fmt.Sprintf("database:%s:%d:%s", db.Host, db.Port, db.Name))
	}
	for _, service := range moduleServiceNames(r.Descriptor) {
		keys = append(keys, "service:"+service)
	}
	sort.Strings(keys)
	keys = compactModuleStrings(keys)
	type heldLock struct {
		key  string
		lock *moduleResourceLock
	}
	var held []heldLock
	dropReference := func(key string, lock *moduleResourceLock) {
		moduleOperationLocks.Lock()
		lock.references--
		if lock.references == 0 {
			delete(moduleOperationLocks.entries, key)
		}
		moduleOperationLocks.Unlock()
	}
	release := func() {
		for i := len(held) - 1; i >= 0; i-- {
			item := held[i]
			<-item.lock.token
			dropReference(item.key, item.lock)
		}
	}
	for _, key := range keys {
		if err := ctx.Err(); err != nil {
			release()
			return nil, err
		}
		moduleOperationLocks.Lock()
		lock := moduleOperationLocks.entries[key]
		if lock == nil {
			lock = &moduleResourceLock{token: make(chan struct{}, 1)}
			moduleOperationLocks.entries[key] = lock
		}
		lock.references++
		moduleOperationLocks.Unlock()
		select {
		case lock.token <- struct{}{}:
			held = append(held, heldLock{key, lock})
		case <-ctx.Done():
			dropReference(key, lock)
			release()
			return nil, ctx.Err()
		}
	}
	return release, nil
}
