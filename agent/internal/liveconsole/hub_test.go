package liveconsole

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestBoundedHistoryAndSlowViewer(t *testing.T) {
	h := NewHub()
	defer h.Close()
	_, ch, stop, err := h.Subscribe(Resume{})
	if err != nil {
		t.Fatal(err)
	}
	defer stop()
	block := bytes.Repeat([]byte("x"), ChunkBytes)
	for i := 0; i < 100000; i++ {
		h.Publish(block)
	}
	h.mu.Lock()
	size, retained, viewers := h.size, h.bytes, len(h.subscribers)
	h.mu.Unlock()
	if retained > TailBytes || size > tailFrames || viewers != 0 {
		t.Fatalf("frames=%d bytes=%d viewers=%d", size, retained, viewers)
	}
	n := 0
	for range ch {
		n++
	}
	if n != subscriberFrames {
		t.Fatal(n)
	}
	t.Logf("100000 output blocks: retained payload=%d bytes; slow viewer queue=%d frames; disconnected=%t", retained, n, viewers == 0)
}
func TestAtomicReplayAndSessionGap(t *testing.T) {
	h := NewHub()
	defer h.Close()
	h.Publish([]byte("first"))
	history, events, stop, err := h.Subscribe(Resume{})
	if err != nil {
		t.Fatal(err)
	}
	defer stop()
	if len(history) != 2 {
		t.Fatal(history)
	}
	h.Publish([]byte("next"))
	next := <-events
	if next.Seq != 2 {
		t.Fatal(next)
	}
	replay, _, stop2, err := h.Subscribe(Resume{history[0].Session, 1})
	if err != nil {
		t.Fatal(err)
	}
	defer stop2()
	if len(replay) != 2 || string(replay[1].Data) != "next" {
		t.Fatal(replay)
	}
	gap, _, stop3, err := h.Subscribe(Resume{"previous-wrapper", 400})
	if err != nil {
		t.Fatal(err)
	}
	defer stop3()
	if gap[1].Status != "history_gap" {
		t.Fatal(gap)
	}
}
func TestViewerLimit(t *testing.T) {
	h := NewHub()
	defer h.Close()
	for i := 0; i < MaxViewers; i++ {
		_, _, stop, err := h.Subscribe(Resume{})
		if err != nil {
			t.Fatal(err)
		}
		defer stop()
	}
	if _, _, _, err := h.Subscribe(Resume{}); err == nil {
		t.Fatal("unlimited viewers")
	}
}
func TestIdleLocalDisconnectReleasesSubscription(t *testing.T) {
	path := filepath.Join(t.TempDir(), "output.sock")
	l, err := ListenLocal(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	h := NewHub()
	defer h.Close()
	done := make(chan struct{})
	go func() { defer close(done); _ = ServeOutput(ctx, l, h) }()
	c, err := DialLocal(ctx, path)
	if err != nil {
		t.Fatal(err)
	}
	_ = json.NewEncoder(c).Encode(Resume{})
	var hello Frame
	_ = c.SetReadDeadline(time.Now().Add(time.Second))
	if err = json.NewDecoder(c).Decode(&hello); err != nil {
		t.Fatal(err)
	}
	_ = c.Close()
	until := time.Now().Add(time.Second)
	for {
		h.mu.Lock()
		n := len(h.subscribers)
		h.mu.Unlock()
		if n == 0 {
			break
		}
		if time.Now().After(until) {
			t.Fatal("idle subscriber leaked")
		}
		time.Sleep(time.Millisecond)
	}
	cancel()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("server leaked")
	}
}
func TestBoundedCompatibilityLog(t *testing.T) {
	path := filepath.Join(t.TempDir(), "console.log")
	l, err := OpenLog(path, true)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = l.Close() }()
	for i := 0; i < 100; i++ {
		if _, err = l.Write(bytes.Repeat([]byte("x"), 16384)); err != nil {
			t.Fatal(err)
		}
	}
	st, _ := os.Stat(path)
	if st.Size() > LogBytes {
		t.Fatal(st.Size())
	}
	huge := bytes.Repeat([]byte("y"), LogBytes*3)
	if n, err := l.Write(huge); err != nil || n != len(huge) {
		t.Fatal(n, err)
	}
	st, _ = os.Stat(path)
	if st.Size() != LogBytes {
		t.Fatal(st.Size())
	}
}
func TestLiveIPCDoesNotWaitForNewline(t *testing.T) {
	path := filepath.Join(t.TempDir(), "output.sock")
	l, err := ListenLocal(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	h := NewHub()
	defer h.Close()
	go func() { _ = ServeOutput(ctx, l, h) }()
	c, err := DialLocal(ctx, path)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = c.Close() }()
	_ = json.NewEncoder(c).Encode(Resume{})
	dec := json.NewDecoder(c)
	_ = c.SetReadDeadline(time.Now().Add(time.Second))
	var f Frame
	if err = dec.Decode(&f); err != nil {
		t.Fatal(err)
	}
	h.Publish([]byte("partial"))
	if err = dec.Decode(&f); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(f.Data, []byte("partial")) {
		t.Fatal(f)
	}
}
