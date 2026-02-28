package validator

import (
	"context"
	"fmt"
	"sort"
	"strings"
	"sync"
	"time"
)

type Engine struct {
	resolver    DNSResolver
	tlsProber   TLSProber
	mtaSTS      MTASTSFetcher
	limiter     RateLimiter
	backoff     BackoffPolicy
	maxAttempts int
	parallelism int
}

func NewEngine(resolver DNSResolver, tlsProber TLSProber, mtaSTS MTASTSFetcher, limiter RateLimiter, backoff BackoffPolicy) *Engine {
	if limiter == nil {
		limiter = NewGlobalLimiter(5)
	}
	if backoff == nil {
		backoff = NewExponentialBackoff(200 * time.Millisecond)
	}
	return &Engine{
		resolver:    resolver,
		tlsProber:   tlsProber,
		mtaSTS:      mtaSTS,
		limiter:     limiter,
		backoff:     backoff,
		maxAttempts: 3,
		parallelism: 6,
	}
}

func (e *Engine) ValidateDomain(ctx context.Context, req DomainValidationRequest) (DomainValidationResult, error) {
	domain := strings.ToLower(strings.TrimSpace(req.Domain))
	if domain == "" {
		return DomainValidationResult{}, fmt.Errorf("domain is required")
	}
	selector := strings.ToLower(strings.TrimSpace(req.Selector))
	if selector == "" {
		selector = "default"
	}
	if req.TLSPort <= 0 {
		req.TLSPort = 587
	}
	if req.TLSHost == "" {
		req.TLSHost = domain
	}

	findings := make([]Finding, 0, 8)
	findingsMu := sync.Mutex{}
	sem := make(chan struct{}, e.parallelism)
	wg := sync.WaitGroup{}

	checks := []func(context.Context, DomainValidationRequest) Finding{
		e.checkSPF,
		e.checkDKIM,
		e.checkDMARC,
		e.checkMX,
		e.checkPTR,
		e.checkTLS,
	}
	if req.MTASTSEnabled {
		checks = append(checks, e.checkMTASTS)
	}

	for _, check := range checks {
		checkFn := check
		wg.Add(1)
		go func() {
			defer wg.Done()
			select {
			case <-ctx.Done():
				return
			case sem <- struct{}{}:
			}
			defer func() { <-sem }()
			finding := checkFn(ctx, DomainValidationRequest{
				Domain:            domain,
				Selector:          selector,
				ExpectedMXTargets: req.ExpectedMXTargets,
				KnownIPs:          req.KnownIPs,
				TLSHost:           req.TLSHost,
				TLSPort:           req.TLSPort,
				MTASTSEnabled:     req.MTASTSEnabled,
				Timeout:           req.Timeout,
			})
			findingsMu.Lock()
			findings = append(findings, finding)
			findingsMu.Unlock()
		}()
	}
	wg.Wait()

	sort.SliceStable(findings, func(i, j int) bool { return findings[i].Check < findings[j].Check })

	result := DomainValidationResult{
		Domain:           domain,
		DkimStatus:       statusForCheck(findings, "dkim"),
		SpfStatus:        statusForCheck(findings, "spf"),
		DmarcStatus:      statusForCheck(findings, "dmarc"),
		MxStatus:         statusForCheck(findings, "mx"),
		TLSStatus:        statusForCheck(findings, "tls"),
		DnsLastCheckedAt: time.Now().UTC(),
		Findings:         findings,
	}

	return result, nil
}

func statusForCheck(findings []Finding, prefix string) Status {
	for _, finding := range findings {
		if finding.Check != prefix {
			continue
		}
		return finding.Status
	}
	return StatusError
}

func (e *Engine) withRetry(ctx context.Context, fn func(context.Context) error) error {
	var lastErr error
	for attempt := 1; attempt <= e.maxAttempts; attempt++ {
		if err := e.limiter.Wait(ctx); err != nil {
			return err
		}
		err := fn(ctx)
		if err == nil {
			return nil
		}
		lastErr = err
		if attempt == e.maxAttempts {
			break
		}
		backoffMs := e.backoff.Duration(attempt)
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(time.Duration(backoffMs) * time.Millisecond):
		}
	}
	return lastErr
}
