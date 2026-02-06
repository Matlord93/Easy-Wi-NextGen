package fileapi

import "sync"

type lockManager struct {
	mu    sync.Mutex
	locks map[string]*sync.RWMutex
}

func newLockManager() *lockManager {
	return &lockManager{locks: make(map[string]*sync.RWMutex)}
}

func (m *lockManager) lockForServer(serverID string) *sync.RWMutex {
	m.mu.Lock()
	defer m.mu.Unlock()
	lock := m.locks[serverID]
	if lock == nil {
		lock = &sync.RWMutex{}
		m.locks[serverID] = lock
	}
	return lock
}

func (m *lockManager) WithReadLock(serverID string, fn func() error) error {
	lock := m.lockForServer(serverID)
	lock.RLock()
	defer lock.RUnlock()
	return fn()
}

func (m *lockManager) WithWriteLock(serverID string, fn func() error) error {
	lock := m.lockForServer(serverID)
	lock.Lock()
	defer lock.Unlock()
	return fn()
}
