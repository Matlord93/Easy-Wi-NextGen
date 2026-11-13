// Package liveconsole carries bounded, event-driven game output. It never owns
// the game lifecycle: disconnecting a viewer cannot stop the game process.
package liveconsole

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"sync"
	"time"
)

const (
	ChunkBytes       = 4096
	TailBytes        = 128 * 1024
	tailFrames       = 128
	subscriberFrames = 8
	MaxViewers       = 16
)

type Frame struct {
	Instance string `json:"instance_id,omitempty"`
	Type     string `json:"type"`
	Session  string `json:"session_id,omitempty"`
	Seq      uint64 `json:"seq,omitempty"`
	Data     []byte `json:"chunk_base64,omitempty"`
	Status   string `json:"status,omitempty"`
}
type Resume struct {
	Session string `json:"session_id"`
	Seq     uint64 `json:"seq"`
}
type Hub struct {
	instance           string
	mu                 sync.Mutex
	session            string
	seq                uint64
	ring               [tailFrames]Frame
	start, size, bytes int
	next               int
	subscribers        map[int]chan Frame
	closed             bool
}

func NewHub() *Hub {
	raw := make([]byte, 16)
	if _, err := rand.Read(raw); err != nil {
		panic(err)
	}
	return &Hub{session: hex.EncodeToString(raw), subscribers: make(map[int]chan Frame)}
}
func (h *Hub) Publish(data []byte) {
	for len(data) > 0 {
		n := len(data)
		if n > ChunkBytes {
			n = ChunkBytes
		}
		h.publish(data[:n])
		data = data[n:]
	}
}
func (h *Hub) publish(data []byte) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.closed {
		return
	}
	h.seq++
	f := Frame{Instance: h.instance, Type: "chunk", Session: h.session, Seq: h.seq, Data: append([]byte(nil), data...)}
	for h.size > 0 && (h.size == tailFrames || h.bytes+len(data) > TailBytes) {
		h.bytes -= len(h.ring[h.start].Data)
		h.ring[h.start] = Frame{}
		h.start = (h.start + 1) % tailFrames
		h.size--
	}
	h.ring[(h.start+h.size)%tailFrames] = f
	h.size++
	h.bytes += len(data)
	for id, ch := range h.subscribers {
		select {
		case ch <- f:
		default:
			close(ch)
			delete(h.subscribers, id)
		}
	}
}

// Subscribe snapshots history and attaches future events under the same lock.
// A slow subscriber is disconnected rather than blocking the producer.
func (h *Hub) Subscribe(r Resume) ([]Frame, <-chan Frame, func(), error) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.closed {
		return nil, nil, nil, errors.New("console closed")
	}
	if len(h.subscribers) >= MaxViewers {
		return nil, nil, nil, errors.New("console viewer capacity reached")
	}
	history := []Frame{{Instance: h.instance, Type: "status", Session: h.session, Status: "connected"}}
	same := r.Session == h.session
	if r.Session != "" && (!same || r.Seq > h.seq || (h.size > 0 && r.Seq+1 < h.ring[h.start].Seq)) {
		history = append(history, Frame{Type: "status", Session: h.session, Status: "history_gap"})
	}
	if !same || r.Seq > h.seq {
		r.Seq = 0
	}
	for i := 0; i < h.size; i++ {
		f := h.ring[(h.start+i)%tailFrames]
		if f.Seq > r.Seq {
			history = append(history, f)
		}
	}
	h.next++
	id := h.next
	ch := make(chan Frame, subscriberFrames)
	h.subscribers[id] = ch
	return history, ch, func() {
		h.mu.Lock()
		defer h.mu.Unlock()
		if c, ok := h.subscribers[id]; ok {
			delete(h.subscribers, id)
			close(c)
		}
	}, nil
}
func (h *Hub) Close() {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.closed {
		return
	}
	h.closed = true
	for id, ch := range h.subscribers {
		close(ch)
		delete(h.subscribers, id)
	}
	clear(h.ring[:])
	h.bytes = 0
	h.size = 0
}

var pingInterval = 15 * time.Second
