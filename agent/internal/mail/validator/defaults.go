package validator

import (
	"bufio"
	"context"
	"crypto/tls"
	"fmt"
	"io"
	"net"
	"net/http"
	"strings"
	"time"
)

type netResolver struct {
	resolver *net.Resolver
}

func NewNetResolver() DNSResolver {
	return &netResolver{resolver: net.DefaultResolver}
}

func (r *netResolver) LookupTXT(ctx context.Context, name string) ([]string, error) {
	return r.resolver.LookupTXT(ctx, name)
}

func (r *netResolver) LookupMX(ctx context.Context, name string) ([]*MXRecord, error) {
	records, err := r.resolver.LookupMX(ctx, name)
	if err != nil {
		return nil, err
	}
	out := make([]*MXRecord, 0, len(records))
	for _, record := range records {
		out = append(out, &MXRecord{Host: strings.TrimSuffix(record.Host, "."), Pref: record.Pref})
	}
	return out, nil
}

func (r *netResolver) LookupAddr(ctx context.Context, addr string) ([]string, error) {
	return r.resolver.LookupAddr(ctx, addr)
}

type startTLSProber struct{}

func NewSTARTTLSProber() TLSProber {
	return &startTLSProber{}
}

func (p *startTLSProber) ProbeSTARTTLS(ctx context.Context, host string, port int) (TLSProbeResult, error) {
	dialer := &net.Dialer{}
	conn, err := dialer.DialContext(ctx, "tcp", fmt.Sprintf("%s:%d", host, port))
	if err != nil {
		return TLSProbeResult{}, err
	}
	defer conn.Close()

	if err = conn.SetDeadline(time.Now().Add(5 * time.Second)); err != nil {
		return TLSProbeResult{}, err
	}

	_, _ = conn.Write([]byte("EHLO validator.local\r\n"))
	reader := bufio.NewReader(conn)
	_, _ = reader.ReadString('\n')
	_, err = conn.Write([]byte("STARTTLS\r\n"))
	if err != nil {
		return TLSProbeResult{}, err
	}
	response, err := reader.ReadString('\n')
	if err != nil {
		return TLSProbeResult{}, err
	}
	if !strings.HasPrefix(strings.TrimSpace(response), "220") {
		return TLSProbeResult{}, fmt.Errorf("STARTTLS rejected: %s", strings.TrimSpace(response))
	}

	tlsConn := tls.Client(conn, &tls.Config{ServerName: host, MinVersion: tls.VersionTLS12})
	if err = tlsConn.HandshakeContext(ctx); err != nil {
		return TLSProbeResult{}, err
	}
	state := tlsConn.ConnectionState()
	if len(state.PeerCertificates) == 0 {
		return TLSProbeResult{}, fmt.Errorf("no peer certificate")
	}
	cert := state.PeerCertificates[0]

	return TLSProbeResult{
		NotAfter:   cert.NotAfter.UTC().Format(time.RFC3339),
		DNSNames:   cert.DNSNames,
		CommonName: cert.Subject.CommonName,
	}, nil
}

type mtaSTSFetcher struct {
	client *http.Client
}

func NewMTASTSFetcher() MTASTSFetcher {
	return &mtaSTSFetcher{client: &http.Client{Timeout: 6 * time.Second}}
}

func (f *mtaSTSFetcher) FetchPolicy(ctx context.Context, domain string) (string, error) {
	url := fmt.Sprintf("https://mta-sts.%s/.well-known/mta-sts.txt", domain)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return "", err
	}
	resp, err := f.client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", fmt.Errorf("status %d", resp.StatusCode)
	}
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", err
	}
	return string(body), nil
}

type tickerLimiter struct {
	tokens <-chan time.Time
}

func NewGlobalLimiter(rps int) RateLimiter {
	if rps <= 0 {
		rps = 5
	}
	interval := time.Second / time.Duration(rps)
	return &tickerLimiter{tokens: time.Tick(interval)}
}

func (l *tickerLimiter) Wait(ctx context.Context) error {
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-l.tokens:
		return nil
	}
}

type exponentialBackoff struct {
	base time.Duration
}

func NewExponentialBackoff(base time.Duration) BackoffPolicy {
	if base <= 0 {
		base = 200 * time.Millisecond
	}
	return &exponentialBackoff{base: base}
}

func (b *exponentialBackoff) Duration(attempt int) int {
	if attempt < 1 {
		attempt = 1
	}
	return int((b.base * time.Duration(1<<(attempt-1))).Milliseconds())
}
