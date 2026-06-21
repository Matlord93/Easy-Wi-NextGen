package musicbotruntime

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

type TrackSourceType string

const (
	TrackSourceUpload TrackSourceType = "upload"
	TrackSourceStream TrackSourceType = "stream"
	TrackSourceURL    TrackSourceType = "url"
	TrackSourcePlugin TrackSourceType = "plugin"
)

type CurrentTrack struct {
	ID              string         `json:"id,omitempty"`
	Title           string         `json:"title"`
	Artist          string         `json:"artist,omitempty"`
	DurationSeconds int            `json:"duration_seconds"`
	Source          AudioSource    `json:"source"`
	PositionSeconds int            `json:"position_seconds"`
	StartedAt       string         `json:"started_at,omitempty"`
	Metadata        map[string]any `json:"metadata,omitempty"`
}

type QueueTrack struct {
	QueueItemID     string         `json:"queue_item_id"`
	TrackID         string         `json:"track_id"`
	Title           string         `json:"title"`
	Artist          string         `json:"artist,omitempty"`
	DurationSeconds int            `json:"duration_seconds"`
	Source          AudioSource    `json:"source"`
	Metadata        map[string]any `json:"metadata,omitempty"`
}

type QueueSnapshot struct {
	InstanceID  string        `json:"instance_id"`
	Current     *CurrentTrack `json:"current,omitempty"`
	Items       []QueueTrack  `json:"items"`
	Repeat      string        `json:"repeat"`
	Shuffle     bool          `json:"shuffle"`
	Revision    uint64        `json:"revision"`
	GeneratedAt string        `json:"generated_at"`
}

type AudioSource struct {
	Type     TrackSourceType `json:"type"`
	URI      string          `json:"uri"`
	MimeType string          `json:"mime_type"`
	Codec    string          `json:"codec,omitempty"`
	Metadata map[string]any  `json:"metadata,omitempty"`
}

type AudioSourceResolver interface {
	Resolve(ctx context.Context, source AudioSource) (ResolvedAudioSource, error)
}

type ResolvedAudioSource struct {
	Source AudioSource
	Path   string
	Size   int64
	Reader io.ReadCloser
}

type DecodedAudioStream interface {
	NextFrame(ctx context.Context) (AudioFrame, error)
	Close() error
}

type AudioDecoder interface {
	Supports(source AudioSource) bool
	Open(ctx context.Context, source AudioSource) (DecodedAudioStream, error)
}

// AudioDecoderBackendName is implemented by decoder adapters that can expose
// their active backend in runtime status.
type AudioDecoderBackendName interface {
	BackendName() string
}

type AudioResampler interface {
	Resample(ctx context.Context, frame AudioFrame, sampleRateHz int, channels int) (AudioFrame, error)
}

type AudioEncoder interface {
	EncodeOpus(ctx context.Context, frame AudioFrame) (AudioFrame, error)
}

type AudioPipelineMetrics interface {
	Snapshot() AudioPipelineStatus
}

type AudioPipelineStatus struct {
	CurrentSource      string `json:"current_source,omitempty"`
	DecoderBackend     string `json:"decoder_backend"`
	DecoderStatus      string `json:"decoder_status"`
	OutputBackend      string `json:"output_backend"`
	OutputStatus       string `json:"output_status"`
	FramesProcessed    uint64 `json:"frames_processed"`
	FramesSent         uint64 `json:"frames_sent"`
	PlaybackPositionMs uint64 `json:"playback_position_ms"`
	LastError          string `json:"last_error,omitempty"`
	LastOutputError    string `json:"last_output_error,omitempty"`
	UpdatedAt          string `json:"updated_at"`
}

// AudioOutputName is implemented by AudioOutput adapters that expose a human-readable backend name.
type AudioOutputName interface {
	OutputName() string
}

type AudioPipeline struct {
	resolver  AudioSourceResolver
	decoder   AudioDecoder
	resampler AudioResampler
	encoder   AudioEncoder
	output    AudioOutput
	mu        sync.Mutex
	status    AudioPipelineStatus
}

func NewAudioPipeline(resolver AudioSourceResolver, decoder AudioDecoder, resampler AudioResampler, encoder AudioEncoder, output AudioOutput) *AudioPipeline {
	if resolver == nil {
		resolver = NewFileAudioSourceResolver("")
	}
	if decoder == nil {
		decoder = DummyDecoder{}
	}
	if resampler == nil {
		resampler = DummyResampler{}
	}
	if encoder == nil {
		encoder = DummyOpusEncoder{}
	}
	if output == nil {
		output = NullAudioOutput{}
	}
	return &AudioPipeline{resolver: resolver, decoder: decoder, resampler: resampler, encoder: encoder, output: output, status: AudioPipelineStatus{DecoderBackend: decoderBackendName(decoder), DecoderStatus: "idle", OutputBackend: outputBackendName(output), OutputStatus: "idle", UpdatedAt: time.Now().UTC().Format(time.RFC3339)}}
}

func (p *AudioPipeline) LoadSource(ctx context.Context, source AudioSource) (ResolvedAudioSource, error) {
	resolved, err := p.resolver.Resolve(ctx, source)
	if err != nil {
		p.setError(err)
		return ResolvedAudioSource{}, err
	}
	p.mu.Lock()
	p.status.CurrentSource = resolved.Source.URI
	p.status.DecoderBackend = decoderBackendName(p.decoder)
	p.status.DecoderStatus = "source_loaded"
	p.status.FramesProcessed = 0
	p.status.FramesSent = 0
	p.status.PlaybackPositionMs = 0
	p.status.LastError = ""
	p.status.LastOutputError = ""
	p.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	p.mu.Unlock()
	return resolved, nil
}

func (p *AudioPipeline) Decode(ctx context.Context, source AudioSource) (DecodedAudioStream, error) {
	if !p.decoder.Supports(source) {
		err := fmt.Errorf("unsupported audio format: %s", firstNonEmpty(source.MimeType, source.Codec, filepath.Ext(source.URI)))
		p.setError(err)
		return nil, err
	}
	stream, err := p.decoder.Open(ctx, source)
	if err != nil {
		p.setError(err)
		return nil, err
	}
	p.setDecoderStatus("decoding")
	return stream, nil
}

func (p *AudioPipeline) Resample(ctx context.Context, frame AudioFrame) (AudioFrame, error) {
	return p.resampler.Resample(ctx, frame, 48000, 2)
}

func (p *AudioPipeline) NormalizeVolume(ctx context.Context, frame AudioFrame, volume float64) (AudioFrame, error) {
	select {
	case <-ctx.Done():
		p.setError(ctx.Err())
		return AudioFrame{}, ctx.Err()
	default:
	}
	if volume < 0 {
		err := errors.New("volume must be non-negative")
		p.setError(err)
		return AudioFrame{}, err
	}
	frame.Metadata = cloneFrameMetadata(frame.Metadata)
	frame.Metadata["volume"] = volume
	return frame, nil
}

func (p *AudioPipeline) EncodeOpus(ctx context.Context, frame AudioFrame) (AudioFrame, error) {
	encoded, err := p.encoder.EncodeOpus(ctx, frame)
	if err != nil {
		p.setError(err)
		return AudioFrame{}, err
	}
	return encoded, nil
}

func (p *AudioPipeline) Output(ctx context.Context, frame AudioFrame) error {
	p.mu.Lock()
	output := p.output
	p.mu.Unlock()
	if err := output.SendAudioFrame(ctx, frame); err != nil {
		p.setError(err)
		return err
	}
	p.mu.Lock()
	p.status.OutputStatus = "ready"
	p.status.FramesProcessed++
	p.status.FramesSent++
	p.status.PlaybackPositionMs += uint64(frame.DurationMs)
	p.status.LastError = ""
	p.status.LastOutputError = ""
	p.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	p.mu.Unlock()
	return nil
}

func (p *AudioPipeline) Process(ctx context.Context, source AudioSource) error {
	return p.ProcessWithVolume(ctx, source, 1.0)
}

func (p *AudioPipeline) ProcessWithVolume(ctx context.Context, source AudioSource, volume float64) error {
	resolved, err := p.LoadSource(ctx, source)
	if err != nil {
		return err
	}
	defer func() { _ = resolved.Reader.Close() }()
	stream, err := p.Decode(ctx, resolved.Source)
	if err != nil {
		return err
	}
	defer func() { _ = stream.Close() }()
	for {
		frame, err := stream.NextFrame(ctx)
		if errors.Is(err, io.EOF) {
			p.setDecoderStatus("eof")
			return nil
		}
		if err != nil {
			p.setError(err)
			return err
		}
		frame, err = p.Resample(ctx, frame)
		if err != nil {
			return err
		}
		frame, err = p.NormalizeVolume(ctx, frame, volume)
		if err != nil {
			return err
		}
		frame, err = p.EncodeOpus(ctx, frame)
		if err != nil {
			return err
		}
		if err := p.Output(ctx, frame); err != nil {
			return err
		}
		d := audioFrameDuration(frame)
		if d > 0 {
			timer := time.NewTimer(d)
			select {
			case <-ctx.Done():
				timer.Stop()
				p.setError(ctx.Err())
				return ctx.Err()
			case <-timer.C:
			}
		}
	}
}

func (p *AudioPipeline) Snapshot() AudioPipelineStatus {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.status
}

func (p *AudioPipeline) setDecoderStatus(status string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.status.DecoderStatus = status
	p.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

func (p *AudioPipeline) setError(err error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.status.DecoderStatus = "error"
	p.status.OutputStatus = "error"
	p.status.LastError = err.Error()
	p.status.LastOutputError = err.Error()
	p.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

type FileAudioSourceResolver struct {
	baseDir     string
	allowedDirs []string
}

func NewFileAudioSourceResolver(baseDir string) FileAudioSourceResolver {
	return NewFileAudioSourceResolverWithAllowedDirs(baseDir, nil)
}

func NewFileAudioSourceResolverWithAllowedDirs(baseDir string, allowedDirs []string) FileAudioSourceResolver {
	dirs := append([]string{}, allowedDirs...)
	if baseDir != "" {
		dirs = append([]string{baseDir}, dirs...)
	}
	return FileAudioSourceResolver{baseDir: baseDir, allowedDirs: dirs}
}

func (r FileAudioSourceResolver) Resolve(ctx context.Context, source AudioSource) (ResolvedAudioSource, error) {
	select {
	case <-ctx.Done():
		return ResolvedAudioSource{}, ctx.Err()
	default:
	}
	if source.Type != "" && source.Type != TrackSourceUpload {
		return ResolvedAudioSource{}, fmt.Errorf("unsupported audio source type: %s", source.Type)
	}
	path := source.URI
	if strings.TrimSpace(path) == "" {
		return ResolvedAudioSource{}, errors.New("audio source uri is required")
	}
	if r.baseDir != "" && !filepath.IsAbs(path) {
		path = filepath.Join(r.baseDir, path)
	}
	cleanPath := filepath.Clean(path)
	abs, err := filepath.Abs(cleanPath)
	if err != nil {
		return ResolvedAudioSource{}, err
	}
	cleanPath = abs
	if len(r.allowedDirs) == 0 {
		return ResolvedAudioSource{}, errors.New("no runtime data directory configured for audio sources")
	}
	allowed := false
	for _, dir := range r.allowedDirs {
		base, err := filepath.Abs(filepath.Clean(dir))
		if err != nil || base == "" {
			continue
		}
		if cleanPath == base || strings.HasPrefix(cleanPath, base+string(os.PathSeparator)) {
			allowed = true
			break
		}
	}
	if !allowed {
		return ResolvedAudioSource{}, errors.New("audio source path is outside allowed runtime data directories")
	}
	if !isSupportedAudioPath(cleanPath) && !isSupportedAudioMime(source.MimeType) {
		return ResolvedAudioSource{}, fmt.Errorf("unsupported audio format: %s", filepath.Ext(cleanPath))
	}
	file, err := os.Open(cleanPath)
	if err != nil {
		return ResolvedAudioSource{}, err
	}
	stat, err := file.Stat()
	if err != nil {
		_ = file.Close()
		return ResolvedAudioSource{}, err
	}
	if stat.IsDir() {
		_ = file.Close()
		return ResolvedAudioSource{}, errors.New("audio source must be a file")
	}
	resolved := source
	resolved.URI = cleanPath
	return ResolvedAudioSource{Source: resolved, Path: cleanPath, Size: stat.Size(), Reader: file}, nil
}

type DummyDecoder struct {
	FrameCount int
}

func (d DummyDecoder) Supports(source AudioSource) bool {
	return isSupportedAudioMime(source.MimeType) || isSupportedAudioPath(source.URI) || isSupportedCodec(source.Codec)
}

func (d DummyDecoder) Open(ctx context.Context, source AudioSource) (DecodedAudioStream, error) {
	select {
	case <-ctx.Done():
		return nil, ctx.Err()
	default:
	}
	if !d.Supports(source) {
		return nil, fmt.Errorf("unsupported audio format: %s", firstNonEmpty(source.MimeType, source.Codec, filepath.Ext(source.URI)))
	}
	count := d.FrameCount
	if count <= 0 {
		count = 3
	}
	return &dummyDecodedAudioStream{remaining: count, sampleRateHz: 48000, channels: 2}, nil
}

func (d DummyDecoder) BackendName() string { return "dummy" }

// FFmpegDecoder is an optional process-based decoder backend. It does not link
// FFmpeg libraries; the ffmpeg executable must be installed on the host and be
// available in PATH or configured through BinaryPath.
type FFmpegDecoder struct {
	BinaryPath string
	FrameMs    int
}

func (d FFmpegDecoder) BackendName() string { return "ffmpeg" }

func (d FFmpegDecoder) Supports(source AudioSource) bool {
	return isSupportedAudioMime(source.MimeType) || isSupportedAudioPath(source.URI) || isSupportedCodec(source.Codec)
}

func (d FFmpegDecoder) Open(ctx context.Context, source AudioSource) (DecodedAudioStream, error) {
	if !d.Supports(source) {
		return nil, fmt.Errorf("unsupported audio format: %s", firstNonEmpty(source.MimeType, source.Codec, filepath.Ext(source.URI)))
	}
	bin := strings.TrimSpace(d.BinaryPath)
	if bin == "" {
		bin = "ffmpeg"
	}
	if _, err := exec.LookPath(bin); err != nil {
		return nil, fmt.Errorf("ffmpeg decoder backend unavailable: install FFmpeg and ensure %q is in PATH: %w", bin, err)
	}
	frameMs := d.FrameMs
	if frameMs <= 0 {
		frameMs = 20
	}
	frameSize := 48000 * 2 * 2 * frameMs / 1000
	cmdCtx, cancel := context.WithCancel(ctx)
	cmd := exec.CommandContext(cmdCtx, bin, "-hide_banner", "-nostdin", "-loglevel", "error", "-i", source.URI, "-vn", "-f", "s16le", "-acodec", "pcm_s16le", "-ac", "2", "-ar", "48000", "pipe:1")
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		cancel()
		return nil, err
	}
	stderr := &bytes.Buffer{}
	cmd.Stderr = stderr
	if err := cmd.Start(); err != nil {
		cancel()
		return nil, fmt.Errorf("start ffmpeg decoder: %w", err)
	}
	return &ffmpegDecodedAudioStream{cmd: cmd, cancel: cancel, stdout: stdout, stderr: stderr, frameSize: frameSize, frameMs: frameMs}, nil
}

type ffmpegDecodedAudioStream struct {
	cmd       *exec.Cmd
	cancel    context.CancelFunc
	stdout    io.ReadCloser
	stderr    *bytes.Buffer
	frameSize int
	frameMs   int
	sequence  uint64
	closed    bool
}

func (s *ffmpegDecodedAudioStream) NextFrame(ctx context.Context) (AudioFrame, error) {
	if s.closed {
		return AudioFrame{}, errors.New("decoded stream is closed")
	}
	buf := make([]byte, s.frameSize)
	type readResult struct {
		n   int
		err error
	}
	done := make(chan readResult, 1)
	go func() { n, err := io.ReadFull(s.stdout, buf); done <- readResult{n: n, err: err} }()
	select {
	case <-ctx.Done():
		_ = s.Close()
		return AudioFrame{}, ctx.Err()
	case res := <-done:
		if errors.Is(res.err, io.EOF) || errors.Is(res.err, io.ErrUnexpectedEOF) {
			if res.n == 0 {
				return AudioFrame{}, io.EOF
			}
			buf = buf[:res.n]
		} else if res.err != nil {
			return AudioFrame{}, fmt.Errorf("read ffmpeg pcm frame: %w: %s", res.err, strings.TrimSpace(s.stderr.String()))
		}
	}
	s.sequence++
	pcm := append([]byte(nil), buf...)
	return AudioFrame{Format: "pcm_s16le", SampleRateHz: 48000, SampleRate: 48000, Channels: 2, Sequence: s.sequence, PCM: pcm, Payload: pcm, DurationMs: s.frameMs, Duration: time.Duration(s.frameMs) * time.Millisecond, Timestamp: time.Now().UTC()}, nil
}

func (s *ffmpegDecodedAudioStream) Close() error {
	if s.closed {
		return nil
	}
	s.closed = true
	s.cancel()
	_ = s.stdout.Close()
	if s.cmd.Process != nil {
		_ = s.cmd.Process.Kill()
	}
	_ = s.cmd.Wait()
	return nil
}

func decoderBackendName(decoder AudioDecoder) string {
	if named, ok := decoder.(AudioDecoderBackendName); ok {
		return named.BackendName()
	}
	return "unknown"
}

func outputBackendName(output AudioOutput) string {
	if named, ok := output.(AudioOutputName); ok {
		return named.OutputName()
	}
	return "unknown"
}

func (p *AudioPipeline) OutputBackendName() string {
	p.mu.Lock()
	defer p.mu.Unlock()
	return outputBackendName(p.output)
}

func (p *AudioPipeline) SetOutput(output AudioOutput) {
	if output == nil {
		output = NullAudioOutput{}
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	p.output = output
	p.status.OutputBackend = outputBackendName(output)
	p.status.OutputStatus = "idle"
	p.status.LastOutputError = ""
	p.status.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
}

type dummyDecodedAudioStream struct {
	remaining    int
	sequence     uint64
	sampleRateHz int
	channels     int
	closed       bool
}

func (s *dummyDecodedAudioStream) NextFrame(ctx context.Context) (AudioFrame, error) {
	select {
	case <-ctx.Done():
		return AudioFrame{}, ctx.Err()
	default:
	}
	if s.closed {
		return AudioFrame{}, errors.New("decoded stream is closed")
	}
	if s.remaining <= 0 {
		return AudioFrame{}, io.EOF
	}
	s.remaining--
	s.sequence++
	payload := bytes.Repeat([]byte{byte(s.sequence)}, 16)
	return AudioFrame{Format: "pcm_s16le", SampleRateHz: s.sampleRateHz, SampleRate: s.sampleRateHz, Channels: s.channels, Sequence: s.sequence, PCM: payload, Payload: payload, DurationMs: 20, Duration: 20 * time.Millisecond, Timestamp: time.Now().UTC()}, nil
}

func (s *dummyDecodedAudioStream) Close() error {
	s.closed = true
	return nil
}

type DummyResampler struct{}

func (DummyResampler) Resample(ctx context.Context, frame AudioFrame, sampleRateHz int, channels int) (AudioFrame, error) {
	select {
	case <-ctx.Done():
		return AudioFrame{}, ctx.Err()
	default:
	}
	if sampleRateHz <= 0 || channels <= 0 {
		return AudioFrame{}, errors.New("target sample rate and channels must be positive")
	}
	frame.SampleRateHz = sampleRateHz
	frame.SampleRate = sampleRateHz
	frame.Channels = channels
	return frame, nil
}

type DummyOpusEncoder struct{}

func (DummyOpusEncoder) EncodeOpus(ctx context.Context, frame AudioFrame) (AudioFrame, error) {
	if err := validateAudioFrame(frame); err != nil {
		return AudioFrame{}, err
	}
	select {
	case <-ctx.Done():
		return AudioFrame{}, ctx.Err()
	default:
	}
	frame.Format = "opus"
	if len(frame.Payload) == 0 && len(frame.PCM) > 0 {
		frame.Payload = append([]byte(nil), frame.PCM...)
	}
	frame.PCM = nil
	return frame, nil
}

type NullAudioOutput struct {
	Frames uint64
}

func (o NullAudioOutput) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if err := validateAudioFrame(frame); err != nil {
		return err
	}
	select {
	case <-ctx.Done():
		return ctx.Err()
	default:
	}
	return nil
}

func (o NullAudioOutput) OutputName() string { return "null" }

func isSupportedAudioPath(path string) bool {
	switch strings.ToLower(filepath.Ext(path)) {
	case ".mp3", ".ogg", ".wav", ".flac":
		return true
	default:
		return false
	}
}

func isSupportedAudioMime(mimeType string) bool {
	switch strings.ToLower(strings.TrimSpace(mimeType)) {
	case "audio/mpeg", "audio/mp3", "audio/ogg", "audio/wav", "audio/x-wav", "audio/flac", "audio/x-flac":
		return true
	default:
		return false
	}
}

func isSupportedCodec(codec string) bool {
	switch strings.ToLower(strings.TrimSpace(codec)) {
	case "mp3", "ogg", "wav", "flac":
		return true
	default:
		return false
	}
}

func cloneFrameMetadata(metadata map[string]any) map[string]any {
	clone := map[string]any{}
	for key, value := range metadata {
		clone[key] = value
	}
	return clone
}

type DummyAudioPipeline interface {
	LoadQueue(ctx context.Context, snapshot QueueSnapshot) error
	Start(ctx context.Context) error
	Pause(ctx context.Context) error
	Resume(ctx context.Context) error
	Stop(ctx context.Context) error
	Skip(ctx context.Context) error
	SetRepeat(ctx context.Context, repeat string) error
	SetShuffle(ctx context.Context, shuffle bool) error
	Snapshot(ctx context.Context) QueueSnapshot
}
