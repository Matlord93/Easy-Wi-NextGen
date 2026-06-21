package musicbotruntime

import (
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestFileAudioSourceResolverValidatesFile(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	path := filepath.Join(dir, "track.mp3")
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}

	resolver := NewFileAudioSourceResolver(dir)
	resolved, err := resolver.Resolve(context.Background(), AudioSource{Type: TrackSourceUpload, URI: "track.mp3", MimeType: "audio/mpeg"})
	if err != nil {
		t.Fatalf("Resolve() error = %v", err)
	}
	defer resolved.Reader.Close()
	if resolved.Path != path || resolved.Size != 5 {
		t.Fatalf("resolved = %#v", resolved)
	}
}

func TestAudioPipelineRejectsUnsupportedFormat(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	path := filepath.Join(dir, "track.txt")
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}

	pipeline := NewAudioPipeline(NewFileAudioSourceResolver(dir), DummyDecoder{}, DummyResampler{}, DummyOpusEncoder{}, NullAudioOutput{})
	err := pipeline.Process(context.Background(), AudioSource{Type: TrackSourceUpload, URI: "track.txt", MimeType: "text/plain"})
	if err == nil || !strings.Contains(err.Error(), "unsupported audio format") {
		t.Fatalf("Process() error = %v, want unsupported audio format", err)
	}
	if status := pipeline.Snapshot(); status.LastError == "" {
		t.Fatalf("status did not retain error: %#v", status)
	}
}

func TestDummyDecoderProducesControlledFrames(t *testing.T) {
	t.Parallel()
	decoder := DummyDecoder{FrameCount: 2}
	stream, err := decoder.Open(context.Background(), AudioSource{URI: "track.wav", MimeType: "audio/wav"})
	if err != nil {
		t.Fatalf("Open() error = %v", err)
	}
	defer stream.Close()

	first, err := stream.NextFrame(context.Background())
	if err != nil {
		t.Fatalf("NextFrame(1) error = %v", err)
	}
	second, err := stream.NextFrame(context.Background())
	if err != nil {
		t.Fatalf("NextFrame(2) error = %v", err)
	}
	if first.Sequence != 1 || second.Sequence != 2 || first.Format != "pcm_s16le" || len(first.Payload) == 0 {
		t.Fatalf("frames not controlled: first=%#v second=%#v", first, second)
	}
	if _, err := stream.NextFrame(context.Background()); !errors.Is(err, io.EOF) {
		t.Fatalf("NextFrame(EOF) error = %v, want EOF", err)
	}
}

func TestAudioPipelineDoesNotSwallowOutputErrors(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	path := filepath.Join(dir, "track.flac")
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}
	wantErr := errors.New("output failed")
	pipeline := NewAudioPipeline(NewFileAudioSourceResolver(dir), DummyDecoder{FrameCount: 1}, DummyResampler{}, DummyOpusEncoder{}, failingAudioOutput{err: wantErr})

	err := pipeline.Process(context.Background(), AudioSource{Type: TrackSourceUpload, URI: "track.flac", MimeType: "audio/flac"})
	if !errors.Is(err, wantErr) {
		t.Fatalf("Process() error = %v, want %v", err, wantErr)
	}
	if status := pipeline.Snapshot(); status.LastError != wantErr.Error() || status.FramesProcessed != 0 {
		t.Fatalf("status after output error = %#v", status)
	}
}

type failingAudioOutput struct{ err error }

func (o failingAudioOutput) SendAudioFrame(ctx context.Context, frame AudioFrame) error { return o.err }

func TestFileAudioSourceResolverRejectsMissingFile(t *testing.T) {
	t.Parallel()
	resolver := NewFileAudioSourceResolver(t.TempDir())
	_, err := resolver.Resolve(context.Background(), AudioSource{Type: TrackSourceUpload, URI: "missing.mp3", MimeType: "audio/mpeg"})
	if err == nil || !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("Resolve() error = %v, want missing file", err)
	}
}

func TestFileAudioSourceResolverRejectsOutsideAllowedDataDir(t *testing.T) {
	t.Parallel()
	dataDir := t.TempDir()
	secretDir := t.TempDir()
	secret := filepath.Join(secretDir, "secret.mp3")
	if err := os.WriteFile(secret, []byte("secret"), 0o600); err != nil {
		t.Fatal(err)
	}
	resolver := NewFileAudioSourceResolver(dataDir)
	_, err := resolver.Resolve(context.Background(), AudioSource{Type: TrackSourceUpload, URI: secret, MimeType: "audio/mpeg"})
	if err == nil || !strings.Contains(err.Error(), "outside allowed runtime data directories") {
		t.Fatalf("Resolve() error = %v, want outside allowed data dir", err)
	}
}

func TestFFmpegDecoderMissingBinaryReportsClearError(t *testing.T) {
	t.Parallel()
	decoder := FFmpegDecoder{BinaryPath: "easywi-ffmpeg-definitely-not-installed"}
	_, err := decoder.Open(context.Background(), AudioSource{URI: "track.mp3", MimeType: "audio/mpeg"})
	if err == nil || !strings.Contains(err.Error(), "ffmpeg decoder backend unavailable") || !strings.Contains(err.Error(), "install FFmpeg") {
		t.Fatalf("Open() error = %v, want clear FFmpeg missing error", err)
	}
}

func TestAudioPipelineStopContextAbortsProcessing(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	path := filepath.Join(dir, "track.wav")
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	pipeline := NewAudioPipeline(NewFileAudioSourceResolver(dir), blockingDecoder{}, DummyResampler{}, DummyOpusEncoder{}, NullAudioOutput{})
	done := make(chan error, 1)
	go func() {
		done <- pipeline.Process(ctx, AudioSource{Type: TrackSourceUpload, URI: "track.wav", MimeType: "audio/wav"})
	}()
	cancel()
	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) {
			t.Fatalf("Process() error = %v, want context canceled", err)
		}
	case <-time.After(time.Second):
		t.Fatal("Process() did not stop after context cancellation")
	}
}

type blockingDecoder struct{}

func (blockingDecoder) Supports(source AudioSource) bool { return true }
func (blockingDecoder) Open(ctx context.Context, source AudioSource) (DecodedAudioStream, error) {
	return blockingStream{}, nil
}

type blockingStream struct{}

func (blockingStream) NextFrame(ctx context.Context) (AudioFrame, error) {
	<-ctx.Done()
	return AudioFrame{}, ctx.Err()
}
func (blockingStream) Close() error { return nil }
