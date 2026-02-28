package ptyconsole

import (
	"context"
	"errors"
	"io"
	"os"
	"os/exec"
	"runtime"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/creack/pty"
)

const (
	maxChunkSize         = 4 * 1024
	defaultSubscriberCap = 256
	defaultTailBytes     = 128 * 1024
	defaultDedupTTL      = 2 * time.Minute
	maxSubscriberDrops   = 8
	maxCommandLength     = 1024
)

type CommandAck string

const (
	CommandAckAccepted  CommandAck = "accepted"
	CommandAckDuplicate CommandAck = "duplicate"
)

type CommandResult struct {
	Ack       CommandAck
	Written   bool
	Timestamp time.Time
}

type dedupEntry struct {
	command   string
	createdAt time.Time
	result    CommandResult
}

type subscriber struct {
	ch      chan Event
	dropped int
}

type Capability struct {
	SupportsPTY bool   `json:"supports_pty"`
	OS          string `json:"os"`
	Version     string `json:"version"`
	Fallback    string `json:"fallback"`
}

type EventType string

const (
	EventTypeOutput EventType = "output"
	EventTypeStatus EventType = "status"
)

type Event struct {
	Type       EventType
	InstanceID string
	Chunk      []byte
	Seq        uint64
	Timestamp  time.Time
	Encoding   string
	Status     string
	PID        int
}

type StartSpec struct {
	Command     string
	Args        []string
	Dir         string
	Env         []string
	SoftStopCmd string
	StopTimeout time.Duration
}

type Session struct {
	instanceID string
	spec       StartSpec
	cmd        *exec.Cmd
	ptyFile    *os.File
	startedAt  time.Time
	seq        atomic.Uint64
	mu         sync.Mutex
	subs       map[int]*subscriber
	nextSubID  int
	dedup      map[string]dedupEntry
	dedupTTL   time.Duration
	tail       []byte
	done       chan struct{}
	err        error
	drops      atomic.Uint64
	disconnect atomic.Uint64
}

func sanitizeCommand(input string) string {
	if input == "" {
		return ""
	}
	buf := make([]rune, 0, len(input))
	for _, r := range input {
		if r == '\n' || r == '\r' || r == '\t' {
			buf = append(buf, ' ')
			continue
		}
		if r < 32 || r == 127 {
			continue
		}
		buf = append(buf, r)
	}
	return string(buf)
}

type Manager struct {
	mu       sync.Mutex
	sessions map[string]*Session
	version  string
}

func NewManager(version string) *Manager {
	return &Manager{sessions: map[string]*Session{}, version: version}
}

func (m *Manager) Capability() Capability {
	supports := runtime.GOOS != "windows"
	fallback := ""
	if !supports {
		fallback = "non_interactive_logs_only"
	}
	return Capability{SupportsPTY: supports, OS: runtime.GOOS, Version: m.version, Fallback: fallback}
}

func (m *Manager) Start(ctx context.Context, instanceID string, spec StartSpec, idempotencyKey string) (*Session, error) {
	m.mu.Lock()
	if existing, ok := m.sessions[instanceID]; ok {
		m.mu.Unlock()
		return existing, nil
	}
	m.mu.Unlock()

	if runtime.GOOS == "windows" {
		return nil, errors.New("pty unsupported on windows")
	}
	if spec.Command == "" {
		return nil, errors.New("command required")
	}
	if spec.StopTimeout <= 0 {
		spec.StopTimeout = 10 * time.Second
	}

	cmd := exec.CommandContext(ctx, spec.Command, spec.Args...)
	cmd.Dir = spec.Dir
	cmd.Env = append(os.Environ(), spec.Env...)
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}

	f, err := pty.Start(cmd)
	if err != nil {
		return nil, err
	}

	s := &Session{
		instanceID: instanceID,
		spec:       spec,
		cmd:        cmd,
		ptyFile:    f,
		startedAt:  time.Now().UTC(),
		subs:       map[int]*subscriber{},
		dedup:      map[string]dedupEntry{},
		dedupTTL:   defaultDedupTTL,
		tail:       make([]byte, 0, defaultTailBytes),
		done:       make(chan struct{}),
	}

	m.mu.Lock()
	m.sessions[instanceID] = s
	m.mu.Unlock()

	go s.readLoop()
	go func() {
		err := cmd.Wait()
		s.close(err)
		m.mu.Lock()
		delete(m.sessions, instanceID)
		m.mu.Unlock()
	}()

	return s, nil
}

func (m *Manager) Get(instanceID string) (*Session, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	s, ok := m.sessions[instanceID]
	return s, ok
}

func (s *Session) Attach() (<-chan Event, func()) {
	s.mu.Lock()
	defer s.mu.Unlock()
	id := s.nextSubID
	s.nextSubID++
	ch := make(chan Event, defaultSubscriberCap)
	s.subs[id] = &subscriber{ch: ch}
	return ch, func() {
		s.mu.Lock()
		if existing, ok := s.subs[id]; ok {
			close(existing.ch)
			delete(s.subs, id)
		}
		s.mu.Unlock()
	}
}

func (s *Session) SendCommand(command, idempotencyKey string) (bool, error) {
	result, err := s.SendCommandWithAck(command, idempotencyKey)
	return result.Written, err
}

func (s *Session) SendCommandWithAck(command, idempotencyKey string) (CommandResult, error) {
	now := time.Now().UTC()
	s.cleanupDedup(now)
	if idempotencyKey != "" {
		s.mu.Lock()
		if entry, ok := s.dedup[idempotencyKey]; ok && entry.command == command {
			s.mu.Unlock()
			return CommandResult{Ack: CommandAckDuplicate, Written: false, Timestamp: entry.createdAt}, nil
		}
		s.mu.Unlock()
	}
	command = sanitizeCommand(command)
	if len(command) == 0 {
		return CommandResult{}, errors.New("empty command")
	}
	if len(command) > maxCommandLength {
		return CommandResult{}, errors.New("command too long")
	}
	_, err := io.WriteString(s.ptyFile, command+"\n")
	result := CommandResult{Ack: CommandAckAccepted, Written: err == nil, Timestamp: now}
	if idempotencyKey != "" {
		s.mu.Lock()
		s.dedup[idempotencyKey] = dedupEntry{command: command, createdAt: now, result: result}
		s.mu.Unlock()
	}
	return result, err
}

func (s *Session) GracefulStop(ctx context.Context) error {
	softTimeout := s.spec.StopTimeout / 3
	if softTimeout <= 0 {
		softTimeout = 2 * time.Second
	}
	termTimeout := s.spec.StopTimeout - softTimeout
	if termTimeout <= 0 {
		termTimeout = 5 * time.Second
	}

	if s.spec.SoftStopCmd != "" {
		_, _ = io.WriteString(s.ptyFile, s.spec.SoftStopCmd+"\n")
		select {
		case <-s.done:
			return nil
		case <-time.After(softTimeout):
		case <-ctx.Done():
			return ctx.Err()
		}
	}
	if s.cmd.Process == nil {
		return nil
	}
	_ = syscall.Kill(-s.cmd.Process.Pid, syscall.SIGTERM)
	select {
	case <-s.done:
		return nil
	case <-time.After(termTimeout):
		_ = syscall.Kill(-s.cmd.Process.Pid, syscall.SIGKILL)
		select {
		case <-s.done:
			return nil
		case <-time.After(2 * time.Second):
			return errors.New("session stop timeout exceeded")
		case <-ctx.Done():
			return ctx.Err()
		}
	case <-ctx.Done():
		return ctx.Err()
	}
}

func (s *Session) cleanupDedup(now time.Time) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for key, entry := range s.dedup {
		if now.Sub(entry.createdAt) > s.dedupTTL {
			delete(s.dedup, key)
		}
	}
}

func (s *Session) readLoop() {
	buf := make([]byte, maxChunkSize)
	for {
		n, err := s.ptyFile.Read(buf)
		if n > 0 {
			chunk := make([]byte, n)
			copy(chunk, buf[:n])
			s.appendTail(chunk)
			s.broadcast(Event{Type: EventTypeOutput, InstanceID: s.instanceID, Chunk: chunk, Seq: s.seq.Add(1), Timestamp: time.Now().UTC(), Encoding: "utf-8"})
		}
		if err != nil {
			if !errors.Is(err, io.EOF) {
				s.broadcast(Event{Type: EventTypeStatus, InstanceID: s.instanceID, Status: "read_error", Timestamp: time.Now().UTC()})
			}
			return
		}
	}
}

func (s *Session) appendTail(chunk []byte) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.tail = append(s.tail, chunk...)
	if len(s.tail) > defaultTailBytes {
		s.tail = s.tail[len(s.tail)-defaultTailBytes:]
	}
}

func (s *Session) broadcast(evt Event) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for id, sub := range s.subs {
		select {
		case sub.ch <- evt:
			sub.dropped = 0
		default:
			sub.dropped++
			s.drops.Add(1)
			if sub.dropped >= maxSubscriberDrops {
				close(sub.ch)
				delete(s.subs, id)
				s.disconnect.Add(1)
			}
		}
	}
}

func (s *Session) close(err error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.err == nil {
		s.err = err
	}
	_ = s.ptyFile.Close()
	for id, sub := range s.subs {
		close(sub.ch)
		delete(s.subs, id)
	}
	select {
	case <-s.done:
	default:
		close(s.done)
	}
}
