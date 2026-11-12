// Package vmconsole handles bounded, authenticated RFB streams. It never logs
// tickets, session cookies, upstream URLs or authentication headers.
package vmconsole

import (
	"crypto/des"
	"encoding/binary"
	"errors"
	"io"
	"sync"

	"github.com/gorilla/websocket"
)

// Stream exposes binary WebSocket messages as an ordered RFB byte stream.
type Stream struct {
	Conn   *websocket.Conn
	reader io.Reader
	mu     sync.Mutex
}

func (s *Stream) Read(p []byte) (int, error) {
	for {
		if s.reader == nil {
			kind, r, err := s.Conn.NextReader()
			if err != nil {
				return 0, err
			}
			if kind != websocket.BinaryMessage {
				return 0, errors.New("binary RFB frames required")
			}
			s.reader = r
		}
		n, err := s.reader.Read(p)
		if err == io.EOF {
			s.reader = nil
			if n > 0 {
				return n, nil
			}
			continue
		}
		return n, err
	}
}
func (s *Stream) Write(p []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if err := s.Conn.WriteMessage(websocket.BinaryMessage, p); err != nil {
		return 0, err
	}
	return len(p), nil
}
func (s *Stream) Close() error { return s.Conn.Close() }

// Authenticate consumes the upstream RFB authentication exchange. The browser
// never receives the Proxmox ticket used as the VNC password.
func Authenticate(s io.ReadWriter, password string) error {
	version := make([]byte, 12)
	if _, err := io.ReadFull(s, version); err != nil {
		return err
	}
	if string(version) != "RFB 003.008\n" {
		return errors.New("upstream must support RFB 3.8")
	}
	if _, err := s.Write(version); err != nil {
		return err
	}
	count := []byte{0}
	if _, err := io.ReadFull(s, count); err != nil {
		return err
	}
	if count[0] == 0 {
		return errors.New("upstream refused RFB connection")
	}
	types := make([]byte, int(count[0]))
	if _, err := io.ReadFull(s, types); err != nil {
		return err
	}
	selected := byte(0)
	for _, kind := range types {
		if password != "" && kind == 2 {
			selected = 2
			break
		}
		if password == "" && kind == 1 {
			selected = 1
		}
	}
	if selected == 0 {
		return errors.New("upstream RFB authentication is unsupported")
	}
	if _, err := s.Write([]byte{selected}); err != nil {
		return err
	}
	if selected == 2 {
		challenge := make([]byte, 16)
		if _, err := io.ReadFull(s, challenge); err != nil {
			return err
		}
		response := challengeResponse(password, challenge)
		if _, err := s.Write(response); err != nil {
			return err
		}
	}
	result := make([]byte, 4)
	if _, err := io.ReadFull(s, result); err != nil {
		return err
	}
	if binary.BigEndian.Uint32(result) != 0 {
		return errors.New("upstream RFB authentication failed")
	}
	return nil
}
func challengeResponse(password string, challenge []byte) []byte {
	key := make([]byte, 8)
	copy(key, []byte(password))
	for i, v := range key {
		v = (v&0x55)<<1 | (v&0xaa)>>1
		v = (v&0x33)<<2 | (v&0xcc)>>2
		key[i] = (v&0x0f)<<4 | (v&0xf0)>>4
	}
	cipher, _ := des.NewCipher(key)
	response := make([]byte, 16)
	cipher.Encrypt(response[:8], challenge[:8])
	cipher.Encrypt(response[8:], challenge[8:])
	return response
}
func AcceptBrowser(s io.ReadWriter) error {
	if _, err := s.Write([]byte("RFB 003.008\n")); err != nil {
		return err
	}
	v := make([]byte, 12)
	if _, err := io.ReadFull(s, v); err != nil {
		return err
	}
	if string(v) != "RFB 003.008\n" {
		return errors.New("browser must support RFB 3.8")
	}
	if _, err := s.Write([]byte{1, 1}); err != nil {
		return err
	}
	choice := make([]byte, 1)
	if _, err := io.ReadFull(s, choice); err != nil {
		return err
	}
	if choice[0] != 1 {
		return errors.New("invalid browser RFB negotiation")
	}
	_, err := s.Write(make([]byte, 4))
	return err
}

// Relay terminates both directions as soon as either peer disconnects.
func Relay(a, b io.ReadWriteCloser) {
	done := make(chan struct{}, 2)
	copyStream := func(dst io.Writer, src io.Reader) {
		_, _ = io.CopyBuffer(dst, src, make([]byte, 32<<10))
		done <- struct{}{}
	}
	go copyStream(a, b)
	go copyStream(b, a)
	<-done
	_ = a.Close()
	_ = b.Close()
	<-done
}
