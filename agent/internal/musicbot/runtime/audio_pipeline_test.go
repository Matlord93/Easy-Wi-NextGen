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
	defer func() { _ = resolved.Reader.Close() }()
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
	defer func() { _ = stream.Close() }()

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

func TestFFmpegDecoderProducesPCMFrameMetadata(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	ffmpeg := filepath.Join(dir, "ffmpeg")
	script := "#!/bin/sh\npython3 - <<'PY'\nimport sys\nsys.stdout.buffer.write(b'\\x01' * 3840)\nPY\n"
	if err := os.WriteFile(ffmpeg, []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	track := filepath.Join(dir, "track.mp3")
	if err := os.WriteFile(track, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}
	stream, err := (FFmpegDecoder{BinaryPath: ffmpeg}).Open(context.Background(), AudioSource{URI: track, MimeType: "audio/mpeg"})
	if err != nil {
		t.Fatalf("Open() error = %v", err)
	}
	defer func() { _ = stream.Close() }()
	frame, err := stream.NextFrame(context.Background())
	if err != nil {
		t.Fatalf("NextFrame() error = %v", err)
	}
	if frame.Format != "pcm_s16le" || frame.SampleRateHz != 48000 || frame.Channels != 2 || frame.DurationMs != 20 || len(frame.PCM) != 3840 {
		t.Fatalf("frame = %#v pcm_len=%d, want pcm_s16le 48k stereo 20ms", frame, len(frame.PCM))
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
	status := pipeline.Snapshot()
	// Output errors set LastOutputError and OutputStatus but must NOT set
	// DecoderStatus=error (the decoder was working fine before the output failed).
	if status.LastOutputError != wantErr.Error() {
		t.Errorf("LastOutputError = %q, want %q", status.LastOutputError, wantErr.Error())
	}
	if status.OutputStatus != "error" {
		t.Errorf("OutputStatus = %q, want error", status.OutputStatus)
	}
	if status.DecoderStatus == "error" {
		t.Errorf("DecoderStatus = %q; output error must not mark decoder as errored", status.DecoderStatus)
	}
	if status.FramesProcessed != 0 {
		t.Errorf("FramesProcessed = %d, want 0", status.FramesProcessed)
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

// TestOutputErrorSetsOutputStatusNotDecoderStatus verifies that when the audio
// output layer fails (e.g. PulseAudio not ready), the pipeline sets
// OutputStatus=error and LastOutputError but leaves DecoderStatus unchanged
// so the connector can remain in a ready state.
func TestOutputErrorSetsOutputStatusNotDecoderStatus(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	path := filepath.Join(dir, "track.ogg")
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}

	outputErr := errors.New("external_client_bridge audio not ready")
	pipeline := NewAudioPipeline(
		NewFileAudioSourceResolver(dir),
		DummyDecoder{FrameCount: 1},
		DummyResampler{},
		DummyOpusEncoder{},
		failingAudioOutput{err: outputErr},
	)

	_ = pipeline.Process(context.Background(), AudioSource{Type: TrackSourceUpload, URI: "track.ogg", MimeType: "audio/ogg"})

	snap := pipeline.Snapshot()
	if snap.OutputStatus != "error" {
		t.Errorf("OutputStatus = %q, want error", snap.OutputStatus)
	}
	if snap.LastOutputError != outputErr.Error() {
		t.Errorf("LastOutputError = %q, want %q", snap.LastOutputError, outputErr.Error())
	}
	if snap.DecoderStatus == "error" {
		t.Errorf("DecoderStatus = %q; output failure must not mark decoder as errored", snap.DecoderStatus)
	}
	// LastError must not be set for a pure output failure.
	if snap.LastError != "" {
		t.Errorf("LastError = %q; should be empty for output-only failure", snap.LastError)
	}
}

// TestDecoderErrorStillSetsDecoderStatus verifies that genuine decoder failures
// continue to set DecoderStatus=error (the setError path is untouched).
func TestDecoderErrorStillSetsDecoderStatus(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	path := filepath.Join(dir, "track.ogg")
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}

	decodeErr := errors.New("decoder failure")
	pipeline := NewAudioPipeline(
		NewFileAudioSourceResolver(dir),
		errDecoder{err: decodeErr},
		DummyResampler{},
		DummyOpusEncoder{},
		NullAudioOutput{},
	)

	err := pipeline.Process(context.Background(), AudioSource{Type: TrackSourceUpload, URI: "track.ogg", MimeType: "audio/ogg"})
	if err == nil {
		t.Fatal("expected error from Process()")
	}

	snap := pipeline.Snapshot()
	if snap.DecoderStatus != "error" {
		t.Errorf("DecoderStatus = %q, want error for decoder failure", snap.DecoderStatus)
	}
	if snap.LastError == "" {
		t.Error("LastError should be set for decoder failure")
	}
}

// errDecoder is a decoder that always fails on Open.
type errDecoder struct{ err error }

func (d errDecoder) Supports(AudioSource) bool { return true }
func (d errDecoder) Open(_ context.Context, _ AudioSource) (DecodedAudioStream, error) {
	return nil, d.err
}

func TestFFmpegDecoderAcceptsUnknownExtensionAndBuildsPCMCommand(t *testing.T) {
	dir := t.TempDir()
	ffmpeg := filepath.Join(dir, "ffmpeg")
	script := "#!/bin/sh\nprintf '%s\n' \"$@\" > \"$FFMPEG_ARGS_FILE\"\npython3 - <<'PY'\nimport sys\nsys.stdout.buffer.write(b'\\x01' * 3840)\nPY\n"
	if err := os.WriteFile(ffmpeg, []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	argsFile := filepath.Join(dir, "args.txt")
	t.Setenv("FFMPEG_ARGS_FILE", argsFile)
	track := filepath.Join(dir, "track.weird")
	if err := os.WriteFile(track, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}
	stream, err := (FFmpegDecoder{BinaryPath: ffmpeg}).Open(context.Background(), AudioSource{Type: TrackSourceUpload, URI: track, MimeType: "application/octet-stream"})
	if err != nil {
		t.Fatalf("Open() error = %v", err)
	}
	defer func() { _ = stream.Close() }()
	if _, err := stream.NextFrame(context.Background()); err != nil {
		t.Fatalf("NextFrame() error = %v", err)
	}
	got, err := os.ReadFile(argsFile)
	if err != nil {
		t.Fatal(err)
	}
	args := string(got)
	for _, want := range []string{"-f\ns16le", "-acodec\npcm_s16le", "-ar\n48000", "-ac\n2", "pipe:1"} {
		if !strings.Contains(args, want) {
			t.Fatalf("ffmpeg args = %q, missing %q", args, want)
		}
	}
}

func TestFileResolverAllowsUnknownExtensionForFFmpeg(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	path := filepath.Join(dir, "track.unknown")
	if err := os.WriteFile(path, []byte("dummy"), 0o600); err != nil {
		t.Fatal(err)
	}
	resolved, err := NewFileAudioSourceResolver(dir).Resolve(context.Background(), AudioSource{Type: TrackSourceUpload, URI: "track.unknown"})
	if err != nil {
		t.Fatalf("Resolve() error = %v", err)
	}
	_ = resolved.Reader.Close()
}

func TestFFmpegDecoderAcceptsRadioURL(t *testing.T) {
	dir := t.TempDir()
	ffmpeg := filepath.Join(dir, "ffmpeg")
	script := "#!/bin/sh\nprintf '%s\n' \"$@\" > \"$FFMPEG_ARGS_FILE\"\npython3 - <<'PY'\nimport sys\nsys.stdout.buffer.write(b'\\x01' * 3840)\nPY\n"
	if err := os.WriteFile(ffmpeg, []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	argsFile := filepath.Join(dir, "radio_args.txt")
	t.Setenv("FFMPEG_ARGS_FILE", argsFile)
	stream, err := (FFmpegDecoder{BinaryPath: ffmpeg}).Open(context.Background(), AudioSource{Type: TrackSourceRadio, URI: "https://stream.example.com/live.mp3"})
	if err != nil {
		t.Fatalf("Open() error = %v", err)
	}
	defer func() { _ = stream.Close() }()
	if _, err := stream.NextFrame(context.Background()); err != nil {
		t.Fatalf("NextFrame() error = %v", err)
	}
	got, err := os.ReadFile(argsFile)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(got), "https://stream.example.com/live.mp3") {
		t.Fatalf("ffmpeg args = %q, want radio URL", string(got))
	}
}
