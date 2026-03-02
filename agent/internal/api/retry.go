package api

import (
	"context"
	crand "crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"math/rand"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

type RetryClass int

const (
	RetryClassNoRetry RetryClass = iota
	RetryClassSafe
)

type RetryPolicy struct {
	MaxAttempts             int
	BaseBackoff             time.Duration
	MaxBackoff              time.Duration
	JitterFraction          float64
	ConnectTimeout          time.Duration
	ResponseHeaderTimeout   time.Duration
	RequestTimeout          time.Duration
	BreakerFailureThreshold int
	BreakerCooldown         time.Duration
}

func DefaultRetryPolicy() RetryPolicy {
	return RetryPolicy{
		MaxAttempts:             4,
		BaseBackoff:             200 * time.Millisecond,
		MaxBackoff:              2 * time.Second,
		JitterFraction:          0.2,
		ConnectTimeout:          3 * time.Second,
		ResponseHeaderTimeout:   10 * time.Second,
		RequestTimeout:          15 * time.Second,
		BreakerFailureThreshold: 5,
		BreakerCooldown:         10 * time.Second,
	}
}

func NewRetryHTTPClient(policy RetryPolicy) *http.Client {
	transport := &http.Transport{
		Proxy:                 http.ProxyFromEnvironment,
		DialContext:           (&net.Dialer{Timeout: policy.ConnectTimeout, KeepAlive: 30 * time.Second}).DialContext,
		ForceAttemptHTTP2:     true,
		MaxIdleConns:          100,
		IdleConnTimeout:       90 * time.Second,
		TLSHandshakeTimeout:   policy.ConnectTimeout,
		ExpectContinueTimeout: 1 * time.Second,
		ResponseHeaderTimeout: policy.ResponseHeaderTimeout,
	}
	return &http.Client{Transport: transport, Timeout: policy.RequestTimeout}
}

type circuitBreaker struct {
	mu                 sync.Mutex
	consecutiveFailure int
	openedUntil        time.Time
}

func (c *circuitBreaker) allow(now time.Time) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	return !now.Before(c.openedUntil)
}

func (c *circuitBreaker) success() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.consecutiveFailure = 0
	c.openedUntil = time.Time{}
}

func (c *circuitBreaker) failure(now time.Time, policy RetryPolicy) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.consecutiveFailure++
	if c.consecutiveFailure >= policy.BreakerFailureThreshold {
		c.openedUntil = now.Add(policy.BreakerCooldown)
		c.consecutiveFailure = 0
	}
}

func classifyRetry(method, idempotencyKey string) RetryClass {
	m := strings.ToUpper(strings.TrimSpace(method))
	if m == http.MethodGet || m == http.MethodHead || m == http.MethodOptions {
		return RetryClassSafe
	}
	if isMutatingMethod(m) && strings.TrimSpace(idempotencyKey) != "" {
		return RetryClassSafe
	}
	return RetryClassNoRetry
}

func isMutatingMethod(method string) bool {
	switch method {
	case http.MethodPost, http.MethodPut, http.MethodPatch, http.MethodDelete:
		return true
	default:
		return false
	}
}

func parseRetryAfter(v string, now time.Time) (time.Duration, bool) {
	if strings.TrimSpace(v) == "" {
		return 0, false
	}
	if seconds, err := strconv.Atoi(strings.TrimSpace(v)); err == nil {
		if seconds < 0 {
			seconds = 0
		}
		return time.Duration(seconds) * time.Second, true
	}
	if t, err := http.ParseTime(v); err == nil {
		d := t.Sub(now)
		if d < 0 {
			return 0, true
		}
		return d, true
	}
	return 0, false
}

func backoffForAttempt(policy RetryPolicy, attempt int) time.Duration {
	if attempt <= 1 {
		return 0
	}
	factor := 1 << (attempt - 2)
	delay := time.Duration(factor) * policy.BaseBackoff
	if delay > policy.MaxBackoff {
		delay = policy.MaxBackoff
	}
	if policy.JitterFraction > 0 {
		spread := float64(delay) * policy.JitterFraction
		min := float64(delay) - spread
		max := float64(delay) + spread
		delay = time.Duration(min + rand.Float64()*(max-min))
	}
	if delay < 0 {
		return 0
	}
	return delay
}

func shouldRetryStatus(code int) bool {
	if code == http.StatusTooManyRequests {
		return true
	}
	return code >= 500 && code <= 599
}

func isTimeoutOrTemporary(err error) bool {
	if err == nil {
		return false
	}
	if errors.Is(err, context.DeadlineExceeded) {
		return true
	}
	var nerr net.Error
	if errors.As(err, &nerr) && nerr.Timeout() {
		return true
	}
	return false
}

func sleepWithContext(ctx context.Context, d time.Duration) error {
	if d <= 0 {
		return nil
	}
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

func NewIdempotencyKey() (string, error) {
	b := make([]byte, 16)
	if _, err := crand.Read(b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

func doWithRetry(ctx context.Context, httpClient *http.Client, policy RetryPolicy, breaker *circuitBreaker, class RetryClass, requestFactory func() (*http.Request, error)) (*http.Response, error) {
	attempts := 1
	if class == RetryClassSafe && policy.MaxAttempts > 1 {
		attempts = policy.MaxAttempts
	}
	var lastErr error
	for attempt := 1; attempt <= attempts; attempt++ {
		now := time.Now()
		if !breaker.allow(now) {
			return nil, fmt.Errorf("retry circuit breaker is open")
		}
		if err := sleepWithContext(ctx, backoffForAttempt(policy, attempt)); err != nil {
			return nil, err
		}

		req, err := requestFactory()
		if err != nil {
			return nil, err
		}
		resp, err := httpClient.Do(req)
		if err != nil {
			lastErr = err
			if isTimeoutOrTemporary(err) && attempt < attempts {
				breaker.failure(now, policy)
				continue
			}
			breaker.failure(now, policy)
			return nil, err
		}
		if shouldRetryStatus(resp.StatusCode) && attempt < attempts {
			retryAfter := backoffForAttempt(policy, attempt+1)
			if ra, ok := parseRetryAfter(resp.Header.Get("Retry-After"), now); ok && ra > retryAfter {
				retryAfter = ra
			}
			_ = resp.Body.Close()
			breaker.failure(now, policy)
			if err := sleepWithContext(ctx, retryAfter); err != nil {
				return nil, err
			}
			continue
		}
		if resp.StatusCode >= 200 && resp.StatusCode < 400 {
			breaker.success()
		}
		return resp, nil
	}
	if lastErr != nil {
		return nil, lastErr
	}
	return nil, fmt.Errorf("request attempts exhausted")
}

func DoWithRetryForPanelClient(ctx context.Context, httpClient *http.Client, policy RetryPolicy, class RetryClass, requestFactory func() (*http.Request, error)) (*http.Response, error) {
	return doWithRetry(ctx, httpClient, policy, &circuitBreaker{}, class, requestFactory)
}
