package main

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"os/exec"
	"strings"
	"sync"
	"time"
)

type journalStreamManager struct {
	mu             sync.Mutex
	sessions       map[string]*journalStreamSession
	limiter        chan struct{}
	ttl            time.Duration
	commandFactory func(ctx context.Context, serviceName string) *exec.Cmd
}

type journalStreamSession struct {
	instanceID  string
	serviceName string
	manager     *journalStreamManager

	ctx    context.Context
	cancel context.CancelFunc

	mu          sync.Mutex
	subscribers map[string]JobLogSender
	lastAccess  time.Time
}

func newJournalStreamManager(maxStreams int, ttl time.Duration) *journalStreamManager {
	if maxStreams < 1 {
		maxStreams = 1
	}
	if ttl <= 0 {
		ttl = 75 * time.Second
	}
	return &journalStreamManager{
		sessions: make(map[string]*journalStreamSession),
		limiter:  make(chan struct{}, maxStreams),
		ttl:      ttl,
		commandFactory: func(ctx context.Context, serviceName string) *exec.Cmd {
			return exec.CommandContext(ctx, "journalctl", "-u", serviceName, "-n", "0", "-f", "--no-pager", "--output=cat")
		},
	}
}

func (m *journalStreamManager) Subscribe(instanceID, serviceName, jobID string, sender JobLogSender) (func(), error) {
	if sender == nil || instanceID == "" || serviceName == "" || jobID == "" {
		return func() {}, fmt.Errorf("invalid stream subscribe payload")
	}

	m.mu.Lock()
	session, ok := m.sessions[instanceID]
	if !ok {
		select {
		case m.limiter <- struct{}{}:
		default:
			m.mu.Unlock()
			return func() {}, fmt.Errorf("max journal streams reached")
		}
		session = newJournalStreamSession(m, instanceID, serviceName)
		m.sessions[instanceID] = session
		go session.run()
	}
	m.mu.Unlock()

	session.addSubscriber(jobID, sender)
	return func() {
		session.removeSubscriber(jobID)
	}, nil
}

func newJournalStreamSession(manager *journalStreamManager, instanceID, serviceName string) *journalStreamSession {
	ctx, cancel := context.WithCancel(context.Background())
	return &journalStreamSession{
		instanceID:  instanceID,
		serviceName: serviceName,
		manager:     manager,
		ctx:         ctx,
		cancel:      cancel,
		subscribers: map[string]JobLogSender{},
		lastAccess:  time.Now(),
	}
}

func (s *journalStreamSession) addSubscriber(jobID string, sender JobLogSender) {
	s.mu.Lock()
	s.subscribers[jobID] = sender
	s.lastAccess = time.Now()
	s.mu.Unlock()
}

func (s *journalStreamSession) removeSubscriber(jobID string) {
	s.mu.Lock()
	delete(s.subscribers, jobID)
	s.lastAccess = time.Now()
	s.mu.Unlock()
}

func (s *journalStreamSession) broadcast(line string) {
	if strings.TrimSpace(line) == "" {
		return
	}
	s.mu.Lock()
	s.lastAccess = time.Now()
	targets := make(map[string]JobLogSender, len(s.subscribers))
	for jobID, sender := range s.subscribers {
		targets[jobID] = sender
	}
	s.mu.Unlock()
	for jobID, sender := range targets {
		sendToSubscriber(jobID, sender, line)
	}
}

func sendToSubscriber(jobID string, sender JobLogSender, line string) {
	if sender == nil || jobID == "" || strings.TrimSpace(line) == "" {
		return
	}
	sender.Send(jobID, []string{line}, nil)
}

func (s *journalStreamSession) run() {
	defer s.manager.removeSession(s.instanceID)
	defer func() { <-s.manager.limiter }()

	cmd := s.manager.commandFactory(s.ctx, s.serviceName)
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		s.broadcast(fmt.Sprintf("journalctl stdout error: %v", err))
		return
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		s.broadcast(fmt.Sprintf("journalctl stderr error: %v", err))
		return
	}
	if err := cmd.Start(); err != nil {
		s.broadcast(fmt.Sprintf("journalctl start error: %v", err))
		return
	}

	var wg sync.WaitGroup
	reader := func(r io.Reader, prefix string) {
		defer wg.Done()
		scanner := bufio.NewScanner(r)
		scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)
		scanner.Split(splitLogLines)
		for scanner.Scan() {
			line := strings.TrimSpace(scanner.Text())
			if line == "" {
				continue
			}
			s.broadcast(prefix + line)
		}
		if scanErr := scanner.Err(); scanErr != nil && s.ctx.Err() == nil {
			s.broadcast(fmt.Sprintf("journalctl read error: %v", scanErr))
		}
	}

	wg.Add(2)
	go reader(stdout, "")
	go reader(stderr, "[stderr] ")

	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	done := make(chan struct{})
	go func() {
		wg.Wait()
		close(done)
	}()

	for {
		select {
		case <-done:
			if err := cmd.Wait(); err != nil && s.ctx.Err() == nil {
				s.broadcast(fmt.Sprintf("journalctl error: %v", err))
			}
			return
		case <-ticker.C:
			if s.isExpired() {
				s.cancel()
			}
		case <-s.ctx.Done():
			_ = cmd.Wait()
			return
		}
	}
}

func (s *journalStreamSession) isExpired() bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.subscribers) > 0 {
		return false
	}
	return time.Since(s.lastAccess) > s.manager.ttl
}

func (m *journalStreamManager) HasSession(instanceID string) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	_, ok := m.sessions[instanceID]
	return ok
}

func (m *journalStreamManager) removeSession(instanceID string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.sessions, instanceID)
}
