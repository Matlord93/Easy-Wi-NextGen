package validator

import (
	"context"
	"crypto/tls"
)

type DNSResolver interface {
	LookupTXT(ctx context.Context, name string) ([]string, error)
	LookupMX(ctx context.Context, name string) ([]*MXRecord, error)
	LookupAddr(ctx context.Context, addr string) ([]string, error)
}

type MXRecord struct {
	Host string
	Pref uint16
}

type TLSProbeResult struct {
	NotAfter   string
	DNSNames   []string
	CommonName string
}

type TLSProber interface {
	ProbeSTARTTLS(ctx context.Context, host string, port int) (TLSProbeResult, error)
}

type MTASTSFetcher interface {
	FetchPolicy(ctx context.Context, domain string) (string, error)
}

type RateLimiter interface {
	Wait(ctx context.Context) error
}

type BackoffPolicy interface {
	Duration(attempt int) int
}

var _ = tls.VersionTLS13
