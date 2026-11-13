package liveconsole

import (
	"os"
	"path/filepath"
	"sync"
)

// The compatibility log is a bounded snapshot, never an unbounded file in /run.
const LogBytes = 256 * 1024

type BoundedLog struct {
	mu   sync.Mutex
	file *os.File
	size int64
}

func OpenLog(path string, truncate bool) (*BoundedLog, error) {
	if err := os.MkdirAll(filepath.Dir(path), 0750); err != nil {
		return nil, err
	}
	flags := os.O_CREATE | os.O_WRONLY
	if truncate {
		flags |= os.O_TRUNC
	}
	f, err := os.OpenFile(path, flags, 0640)
	if err != nil {
		return nil, err
	}
	st, err := f.Stat()
	if err != nil {
		f.Close()
		return nil, err
	}
	l := &BoundedLog{file: f, size: st.Size()}
	if l.size > LogBytes {
		_ = f.Truncate(0)
		l.size = 0
	}
	_, err = f.Seek(l.size, 0)
	if err != nil {
		f.Close()
		return nil, err
	}
	return l, nil
}
func (l *BoundedLog) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	original := len(p)
	if len(p) > LogBytes {
		p = p[len(p)-LogBytes:]
	}
	if l.size+int64(len(p)) > LogBytes {
		if err := l.file.Truncate(0); err != nil {
			return 0, err
		}
		if _, err := l.file.Seek(0, 0); err != nil {
			return 0, err
		}
		l.size = 0
	}
	n, err := l.file.Write(p)
	l.size += int64(n)
	if err != nil {
		return n, err
	}
	return original, nil
}
func (l *BoundedLog) Close() error { return l.file.Close() }
