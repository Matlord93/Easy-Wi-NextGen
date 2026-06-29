package musicbotruntime

import (
	"strings"
	"testing"
)

func TestNewRadioStreamSourceAppliesDefaults(t *testing.T) {
	src := NewRadioStreamSource(RadioSourceConfig{
		StreamURL: "http://stream.example.com/live.mp3",
	})
	if src.config.ReconnectPolicy.MaxRetries != 5 {
		t.Errorf("expected default MaxRetries=5, got %d", src.config.ReconnectPolicy.MaxRetries)
	}
	if src.config.ReconnectPolicy.RetryDelaySeconds != 10 {
		t.Errorf("expected default RetryDelaySeconds=10, got %d", src.config.ReconnectPolicy.RetryDelaySeconds)
	}
	if src.config.ReconnectPolicy.BackoffMultiplier != 1.5 {
		t.Errorf("expected default BackoffMultiplier=1.5, got %f", src.config.ReconnectPolicy.BackoffMultiplier)
	}
}

func TestNewRadioStreamSourceUsesResolvedURL(t *testing.T) {
	src := NewRadioStreamSource(RadioSourceConfig{
		StreamURL:   "http://stream.example.com/playlist.m3u",
		ResolvedURL: "http://stream.example.com/live.mp3",
	})
	if src.config.ResolvedURL != "http://stream.example.com/live.mp3" {
		t.Errorf("expected resolved url to be used, got %s", src.config.ResolvedURL)
	}
}

func TestNewRadioStreamSourceFallsBackToStreamURL(t *testing.T) {
	src := NewRadioStreamSource(RadioSourceConfig{
		StreamURL: "http://stream.example.com/live.mp3",
	})
	if src.config.ResolvedURL != "http://stream.example.com/live.mp3" {
		t.Errorf("expected resolved url to fall back to stream_url, got %s", src.config.ResolvedURL)
	}
}

func TestGetStatusIdleByDefault(t *testing.T) {
	src := NewRadioStreamSource(RadioSourceConfig{StreamURL: "http://example.com/s"})
	s := src.GetStatus()
	if s.State != "idle" {
		t.Errorf("expected idle state, got %s", s.State)
	}
}

func TestResolvePlaylistURLM3U(t *testing.T) {
	body := "#EXTM3U\n#EXTINF:-1,My Radio\nhttp://cdn.example.com/stream.mp3\n"
	got := ResolvePlaylistURL("http://example.com/radio.m3u", body)
	if got != "http://cdn.example.com/stream.mp3" {
		t.Errorf("expected resolved M3U url, got %s", got)
	}
}

func TestResolvePlaylistURLM3USkipsComments(t *testing.T) {
	body := "# comment\n#EXTINF:-1\n\nhttp://cdn.example.com/live.ogg\n"
	got := ResolvePlaylistURL("http://example.com/list.m3u8", body)
	if got != "http://cdn.example.com/live.ogg" {
		t.Errorf("expected ogg stream, got %s", got)
	}
}

func TestResolvePlaylistURLPLS(t *testing.T) {
	body := "[playlist]\nFile1=http://icecast.example.net/stream\nTitle1=My Station\nNumberOfEntries=1\n"
	got := ResolvePlaylistURL("http://example.com/station.pls", body)
	if got != "http://icecast.example.net/stream" {
		t.Errorf("expected PLS stream url, got %s", got)
	}
}

func TestResolvePlaylistURLDirectPassthrough(t *testing.T) {
	url := "http://stream.example.com/live.aac"
	got := ResolvePlaylistURL(url, "")
	if got != url {
		t.Errorf("expected direct url passthrough, got %s", got)
	}
}

func TestResolvePlaylistURLM3UNoHTTPURL(t *testing.T) {
	body := "#EXTM3U\n# only comments\n"
	original := "http://example.com/empty.m3u"
	got := ResolvePlaylistURL(original, body)
	if got != original {
		t.Errorf("expected original url when M3U has no http url, got %s", got)
	}
}

func TestCleanICYHeader(t *testing.T) {
	src := &RadioStreamSource{}
	cases := []struct{ in, want string }{
		{"  Rock Radio  ", "Rock Radio"},
		{"", ""},
		{"\tJazz\n", "Jazz"},
	}
	for _, c := range cases {
		got := src.cleanICYHeader(c.in)
		if got != c.want {
			t.Errorf("cleanICYHeader(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}

func TestReconnectPolicyDefaults(t *testing.T) {
	p := defaultRadioReconnectPolicy()
	if p.MaxRetries != 5 {
		t.Errorf("want MaxRetries=5 got %d", p.MaxRetries)
	}
	if p.RetryDelaySeconds != 10 {
		t.Errorf("want RetryDelaySeconds=10 got %d", p.RetryDelaySeconds)
	}
	if p.BackoffMultiplier != 1.5 {
		t.Errorf("want BackoffMultiplier=1.5 got %f", p.BackoffMultiplier)
	}
}

func TestResolvePlaylistURLXSPFPassthrough(t *testing.T) {
	url := "http://example.com/radio.xspf"
	body := strings.Repeat("x", 10)
	// XSPF resolution is handled in PHP; Go falls through to original url
	got := ResolvePlaylistURL(url, body)
	if got != url {
		t.Errorf("expected original url for unsupported format, got %s", got)
	}
}
