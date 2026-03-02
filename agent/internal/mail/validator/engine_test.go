package validator

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"testing"
	"time"
)

type fakeResolver struct {
	mu  sync.RWMutex
	txt map[string][]string
	mx  map[string][]*MXRecord
	ptr map[string][]string
	err map[string]error
}

func (f *fakeResolver) LookupTXT(_ context.Context, name string) ([]string, error) {
	f.mu.RLock()
	defer f.mu.RUnlock()
	if err := f.err["txt:"+name]; err != nil {
		return nil, err
	}
	return f.txt[name], nil
}
func (f *fakeResolver) LookupMX(_ context.Context, name string) ([]*MXRecord, error) {
	f.mu.RLock()
	defer f.mu.RUnlock()
	if err := f.err["mx:"+name]; err != nil {
		return nil, err
	}
	return f.mx[name], nil
}
func (f *fakeResolver) LookupAddr(_ context.Context, addr string) ([]string, error) {
	f.mu.RLock()
	defer f.mu.RUnlock()
	if err := f.err["ptr:"+addr]; err != nil {
		return nil, err
	}
	return f.ptr[addr], nil
}

type fakeTLS struct {
	result TLSProbeResult
	err    error
}

func (f fakeTLS) ProbeSTARTTLS(context.Context, string, int) (TLSProbeResult, error) {
	return f.result, f.err
}

type fakeSTS struct {
	policy string
	err    error
}

func (f fakeSTS) FetchPolicy(context.Context, string) (string, error) { return f.policy, f.err }

type passLimiter struct{}

func (passLimiter) Wait(context.Context) error { return nil }

type noBackoff struct{}

func (noBackoff) Duration(int) int { return 0 }

func TestValidateDomainEdgeCases(t *testing.T) {
	now := time.Now().UTC().Add(24 * time.Hour).Format(time.RFC3339)

	tests := []struct {
		name      string
		request   DomainValidationRequest
		resolver  *fakeResolver
		tls       TLSProber
		sts       MTASTSFetcher
		expect    map[string]Status
		expectErr bool
	}{
		{
			name:     "multiple SPF records",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610"},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all", "v=spf1 ~all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=quarantine; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}, ptr: map[string][]string{}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"spf": StatusError},
		},
		{
			name:     "dkim cname mispublish or malformed txt",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610"},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all"}, "mail202610._domainkey.example.com": {"not-dkim"}, "_dmarc.example.com": {"v=DMARC1; p=quarantine; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"dkim": StatusError},
		},
		{
			name:     "dmarc none policy warning",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610"},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=none; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"dmarc": StatusWarning},
		},
		{
			name:     "missing rua warning",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610"},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=reject"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"dmarc": StatusWarning},
		},
		{
			name:     "mx does not match expected target",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610", ExpectedMXTargets: []string{"mx.panel.example"}},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=reject; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.external.example", Pref: 10}}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"mx": StatusWarning},
		},
		{
			name:     "ptr missing for known ip",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610", KnownIPs: []string{"203.0.113.10"}},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=reject; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}, ptr: map[string][]string{"203.0.113.10": {}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"ptr": StatusWarning},
		},
		{
			name:     "starttls certificate mismatch",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610", TLSHost: "mail.example.com"},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=reject; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"smtp.example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"tls": StatusError},
		},
		{
			name:     "mta sts fetch failure optional warning",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610", MTASTSEnabled: true},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 -all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=reject; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{err: errors.New("fetch failed")},
			expect:   map[string]Status{"mta_sts": StatusWarning},
		},
		{
			name:     "spf permissive plus all",
			request:  DomainValidationRequest{Domain: "example.com", Selector: "mail202610"},
			resolver: &fakeResolver{txt: map[string][]string{"example.com": {"v=spf1 +all"}, "mail202610._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=reject; rua=mailto:a@example.com"}}, mx: map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}}},
			tls:      fakeTLS{result: TLSProbeResult{NotAfter: now, DNSNames: []string{"example.com"}}},
			sts:      fakeSTS{},
			expect:   map[string]Status{"spf": StatusWarning},
		},
		{
			name:      "domain missing",
			request:   DomainValidationRequest{Domain: ""},
			resolver:  &fakeResolver{txt: map[string][]string{}, mx: map[string][]*MXRecord{}, ptr: map[string][]string{}, err: map[string]error{}},
			tls:       fakeTLS{},
			sts:       fakeSTS{},
			expectErr: true,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			if tc.resolver.err == nil {
				tc.resolver.err = map[string]error{}
			}
			engine := NewEngine(tc.resolver, tc.tls, tc.sts, passLimiter{}, noBackoff{})
			result, err := engine.ValidateDomain(context.Background(), tc.request)
			if tc.expectErr {
				if err == nil {
					t.Fatalf("expected error, got nil")
				}
				return
			}
			if err != nil {
				t.Fatalf("unexpected error: %v", err)
			}
			index := map[string]Status{}
			for _, finding := range result.Findings {
				index[finding.Check] = finding.Status
			}
			for key, expected := range tc.expect {
				if got := index[key]; got != expected {
					t.Fatalf("check %s expected %s got %s", key, expected, got)
				}
			}
		})
	}
}

func TestWithRetryBackoff(t *testing.T) {
	resolver := &fakeResolver{
		txt: map[string][]string{"example.com": {"v=spf1 -all"}, "default._domainkey.example.com": {"v=DKIM1; p=abc"}, "_dmarc.example.com": {"v=DMARC1; p=reject; rua=mailto:a@example.com"}},
		mx:  map[string][]*MXRecord{"example.com": {{Host: "mx.example.com", Pref: 10}}},
		err: map[string]error{"txt:example.com": errors.New("temporary")},
	}
	attempts := 0
	engine := NewEngine(resolver, fakeTLS{result: TLSProbeResult{NotAfter: time.Now().Add(time.Hour).Format(time.RFC3339), DNSNames: []string{"example.com"}}}, fakeSTS{}, passLimiter{}, noBackoff{})
	engine.maxAttempts = 2
	engine.backoff = noBackoff{}
	engine.resolver = &retryResolver{base: resolver, onTXT: func(name string) {
		resolver.mu.Lock()
		defer resolver.mu.Unlock()
		attempts++
		if attempts >= 2 {
			delete(resolver.err, "txt:"+name)
		}
	}}

	_, err := engine.ValidateDomain(context.Background(), DomainValidationRequest{Domain: "example.com"})
	if err != nil {
		t.Fatalf("expected success after retry, got %v", err)
	}
}

type retryResolver struct {
	base  *fakeResolver
	onTXT func(name string)
}

func (r *retryResolver) LookupTXT(ctx context.Context, name string) ([]string, error) {
	if r.onTXT != nil {
		r.onTXT(name)
	}
	return r.base.LookupTXT(ctx, name)
}
func (r *retryResolver) LookupMX(ctx context.Context, name string) ([]*MXRecord, error) {
	return r.base.LookupMX(ctx, name)
}
func (r *retryResolver) LookupAddr(ctx context.Context, addr string) ([]string, error) {
	return r.base.LookupAddr(ctx, addr)
}

func ExampleDomainValidationResult() {
	result := DomainValidationResult{
		Domain:           "example.com",
		DkimStatus:       StatusOK,
		SpfStatus:        StatusOK,
		DmarcStatus:      StatusWarning,
		MxStatus:         StatusOK,
		TLSStatus:        StatusOK,
		DnsLastCheckedAt: time.Unix(0, 0).UTC(),
		Findings:         []Finding{{Check: "dmarc", Status: StatusWarning, Severity: SeverityWarning, Details: "DMARC policy none reduces enforcement", ObservedValue: "v=DMARC1; p=none", ExpectedValue: "p=quarantine|reject", Timestamp: time.Unix(0, 0).UTC()}},
	}
	fmt.Printf("%s %s %d\n", result.Domain, result.DkimStatus, len(result.Findings))
	// Output: example.com ok 1
}
