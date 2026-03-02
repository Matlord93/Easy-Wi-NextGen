package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"math"
	"math/rand"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"golang.org/x/sync/singleflight"
)

type voiceQueryGovernor struct {
	mu      sync.Mutex
	cfg     voiceQueryConfig
	sf      singleflight.Group
	nowFn   func() time.Time
	targets map[string]*voiceTargetState
	cache   map[string]voiceCacheEntry
	stats   voiceQueryStats
}

type voiceQueryConfig struct {
	ratePerSecond, burst                                                                    float64
	maxConcurrency                                                                          int
	hardCooldown, baseBackoff, maxBackoff, queryTimeout, statusCacheTTL, invariantsCacheTTL time.Duration
	timeoutCooldownThreshold                                                                int
}
type voiceTargetState struct {
	tokens                        float64
	lastRefill                    time.Time
	inFlight                      int
	cooldownUntil, backoffUntil   time.Time
	consecutive429, consecutiveTO int
}
type voiceCacheEntry struct {
	value     map[string]string
	expiresAt time.Time
}
type voiceQueryStats struct{ retries, banEvents, rateLimited, cacheHits, latencySamples, latencyTotalMs int64 }

type voiceTarget struct{ provider, host, port, user, endpoint string }

func (t voiceTarget) key() string {
	return fmt.Sprintf("%s:%s:%s", strings.ToLower(strings.TrimSpace(t.host)), strings.TrimSpace(t.port), strings.ToLower(strings.TrimSpace(t.user)))
}
func (t voiceTarget) cacheKey(s string) string { return t.key() + ":" + s }

type voiceQueryError struct {
	Code, Message           string
	RetryAfter              int
	Retryable, HardCooldown bool
}

func (e *voiceQueryError) Error() string {
	if e == nil || e.Message == "" {
		return "voice query failed"
	}
	return e.Message
}

func newVoiceQueryGovernor() *voiceQueryGovernor {
	cfg := voiceQueryConfig{ratePerSecond: envFloatAny([]string{"VOICE_QUERY_RPS", "VOICE_QUERY_RATE_PER_SECOND"}, 2), burst: envFloat("VOICE_QUERY_BURST", 3), maxConcurrency: envInt("VOICE_QUERY_MAX_CONCURRENCY", 1), hardCooldown: envDurationAny([]string{"VOICE_BAN_COOLDOWN", "VOICE_QUERY_HARD_COOLDOWN"}, 10*time.Minute), baseBackoff: envDuration("VOICE_QUERY_BACKOFF_BASE", 2*time.Second), maxBackoff: envDuration("VOICE_QUERY_BACKOFF_MAX", 90*time.Second), queryTimeout: envDuration("VOICE_QUERY_TIMEOUT", 3*time.Second), statusCacheTTL: envDuration("VOICE_QUERY_STATUS_CACHE_TTL", 15*time.Second), invariantsCacheTTL: envDuration("VOICE_QUERY_INVARIANT_CACHE_TTL", 3*time.Minute), timeoutCooldownThreshold: envInt("VOICE_QUERY_TIMEOUT_COOLDOWN_THRESHOLD", 3)}
	if cfg.maxConcurrency < 1 {
		cfg.maxConcurrency = 1
	}
	if cfg.burst < 1 {
		cfg.burst = 1
	}
	if cfg.ratePerSecond <= 0 {
		cfg.ratePerSecond = 1
	}
	if cfg.maxBackoff < cfg.baseBackoff {
		cfg.maxBackoff = cfg.baseBackoff
	}
	if cfg.timeoutCooldownThreshold < 1 {
		cfg.timeoutCooldownThreshold = 1
	}
	return &voiceQueryGovernor{cfg: cfg, nowFn: time.Now, targets: map[string]*voiceTargetState{}, cache: map[string]voiceCacheEntry{}}
}

func (g *voiceQueryGovernor) queryStatus(ctx context.Context, target voiceTarget, correlationID string) (map[string]string, error) {
	if cached, ok := g.getCached(target.cacheKey("status")); ok {
		g.hitCache()
		return cached, nil
	}
	v, err, _ := g.sf.Do(target.cacheKey("status"), func() (any, error) {
		if cached, ok := g.getCached(target.cacheKey("status")); ok {
			g.hitCache()
			return cached, nil
		}
		res, err := g.executeQuery(ctx, target, correlationID)
		if err != nil {
			return nil, err
		}
		g.putCached(target.cacheKey("status"), res, g.cfg.statusCacheTTL)
		g.putCached(target.cacheKey("invariants"), mapFromKeys(res, []string{"provider_type", "host", "port", "instance_name"}), g.cfg.invariantsCacheTTL)
		return res, nil
	})
	if err != nil {
		return nil, err
	}
	return cloneStringMap(v.(map[string]string)), nil
}

func (g *voiceQueryGovernor) executeQuery(ctx context.Context, target voiceTarget, correlationID string) (map[string]string, error) {
	now := g.nowFn()
	retry, err := g.acquire(target.key(), now)
	if err != nil {
		g.markRateLimited()
		return nil, &voiceQueryError{Code: "voice_rate_limited", Message: err.Error(), RetryAfter: retry}
	}
	start := time.Now()
	res, qErr := g.callServer(ctx, target, correlationID)
	lat := time.Since(start).Milliseconds()
	g.observeLatency(lat)
	g.finish(target.key(), qErr, correlationID, lat)
	return res, qErr
}

func (g *voiceQueryGovernor) callServer(ctx context.Context, target voiceTarget, correlationID string) (map[string]string, error) {
	if target.endpoint == "" {
		return map[string]string{"status": "online", "players_online": "0", "players_max": "0", "provider_type": target.provider, "host": target.host, "port": target.port}, nil
	}
	client := &http.Client{Timeout: g.cfg.queryTimeout}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, target.endpoint, nil)
	if err != nil {
		return nil, &voiceQueryError{Code: "voice_query_failed", Message: err.Error()}
	}
	req.Header.Set("X-Correlation-ID", correlationID)
	resp, err := client.Do(req)
	if err != nil {
		if n, ok := err.(net.Error); ok && n.Timeout() {
			return nil, &voiceQueryError{Code: "voice_query_timeout", Message: "query timeout", Retryable: true}
		}
		return nil, &voiceQueryError{Code: "voice_query_failed", Message: err.Error(), Retryable: true}
	}
	defer func() {
		_ = resp.Body.Close()
	}()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode == 401 || resp.StatusCode == 403 {
		return nil, &voiceQueryError{Code: "voice_query_auth_failed", Message: "query authentication failed", HardCooldown: true}
	}
	if resp.StatusCode == 429 {
		return nil, &voiceQueryError{Code: "voice_query_rate_limited", Message: "server replied 429", Retryable: true, RetryAfter: parseRetryAfter(resp.Header.Get("Retry-After"))}
	}
	if resp.StatusCode >= 500 {
		return nil, &voiceQueryError{Code: "voice_query_failed", Message: fmt.Sprintf("query server status=%d", resp.StatusCode), Retryable: true}
	}
	if bytesContainsBan(body) {
		return nil, &voiceQueryError{Code: "voice_query_banned", Message: "query server returned ban indicator", HardCooldown: true}
	}
	payload := map[string]any{}
	if len(body) > 0 {
		if err := json.Unmarshal(body, &payload); err != nil {
			return nil, &voiceQueryError{Code: "voice_query_failed", Message: "invalid query response payload"}
		}
	}
	status := strings.TrimSpace(fmt.Sprintf("%v", payload["status"]))
	if status == "" || status == "<nil>" {
		status = "online"
	}
	res := map[string]string{"status": status, "players_online": stringify(payload["players_online"], "0"), "players_max": stringify(payload["players_max"], "0"), "provider_type": target.provider, "host": target.host, "port": target.port}
	if n := stringify(payload["instance_name"], ""); n != "" {
		res["instance_name"] = n
	}
	return res, nil
}

func (g *voiceQueryGovernor) acquire(key string, now time.Time) (int, error) {
	g.mu.Lock()
	defer g.mu.Unlock()
	s := g.stateFor(key, now)
	if s.cooldownUntil.After(now) {
		return retry(s.cooldownUntil, now), errors.New("voice target in cooldown")
	}
	if s.backoffUntil.After(now) {
		return retry(s.backoffUntil, now), errors.New("voice target backing off")
	}
	s.tokens = minFloat(g.cfg.burst, s.tokens+(now.Sub(s.lastRefill).Seconds()*g.cfg.ratePerSecond))
	s.lastRefill = now
	if s.inFlight >= g.cfg.maxConcurrency {
		return 1, errors.New("voice target concurrency limit reached")
	}
	if s.tokens < 1 {
		return maxInt(1, int(math.Ceil((1-s.tokens)/g.cfg.ratePerSecond))), errors.New("voice target token bucket exhausted")
	}
	s.tokens -= 1
	s.inFlight++
	return 0, nil
}
func (g *voiceQueryGovernor) finish(key string, err error, correlationID string, latencyMs int64) {
	now := g.nowFn()
	g.mu.Lock()
	defer g.mu.Unlock()
	s := g.stateFor(key, now)
	if s.inFlight > 0 {
		s.inFlight--
	}
	if err == nil {
		s.consecutive429 = 0
		s.consecutiveTO = 0
		s.backoffUntil = time.Time{}
		return
	}
	var qErr *voiceQueryError
	if !errors.As(err, &qErr) {
		return
	}
	if qErr.HardCooldown {
		s.cooldownUntil = now.Add(g.cfg.hardCooldown)
		s.backoffUntil = s.cooldownUntil
		s.consecutive429 = 0
		s.consecutiveTO = 0
		g.stats.banEvents++
		log.Printf("voice.query.alert target=%s correlation_id=%s code=%s cooldown_seconds=%d", key, correlationID, qErr.Code, int(g.cfg.hardCooldown.Seconds()))
		return
	}
	if qErr.Retryable || qErr.Code == "voice_query_timeout" || qErr.Code == "voice_query_rate_limited" {
		g.stats.retries++
		if qErr.Code == "voice_query_rate_limited" {
			s.consecutive429++
			s.consecutiveTO = 0
		}
		if qErr.Code == "voice_query_timeout" {
			s.consecutiveTO++
			s.consecutive429 = 0
			if s.consecutiveTO >= g.cfg.timeoutCooldownThreshold {
				s.cooldownUntil = now.Add(g.cfg.hardCooldown)
				s.backoffUntil = s.cooldownUntil
				s.consecutiveTO = 0
				g.stats.banEvents++
				log.Printf("voice.query.alert target=%s correlation_id=%s code=voice_query_timeout_pattern cooldown_seconds=%d", key, correlationID, int(g.cfg.hardCooldown.Seconds()))
				return
			}
		}
		attempt := s.consecutive429 + s.consecutiveTO + 1
		wait := qErr.RetryAfter
		if wait <= 0 {
			raw := float64(g.cfg.baseBackoff) * math.Pow(2, float64(minInt(attempt-1, 6)))
			if raw > float64(g.cfg.maxBackoff) {
				raw = float64(g.cfg.maxBackoff)
			}
			wait = maxInt(1, int((raw*((rand.Float64()*0.4)+0.8))/float64(time.Second)))
		}
		s.backoffUntil = now.Add(time.Duration(wait) * time.Second)
		log.Printf("voice.query.backoff target=%s correlation_id=%s wait_seconds=%d code=%s latency_ms=%d", key, correlationID, wait, qErr.Code, latencyMs)
	}
}
func (g *voiceQueryGovernor) stateFor(key string, now time.Time) *voiceTargetState {
	if s, ok := g.targets[key]; ok {
		return s
	}
	s := &voiceTargetState{tokens: g.cfg.burst, lastRefill: now}
	g.targets[key] = s
	return s
}
func (g *voiceQueryGovernor) getCached(key string) (map[string]string, bool) {
	now := g.nowFn()
	g.mu.Lock()
	defer g.mu.Unlock()
	e, ok := g.cache[key]
	if !ok || e.expiresAt.Before(now) {
		delete(g.cache, key)
		return nil, false
	}
	return cloneStringMap(e.value), true
}
func (g *voiceQueryGovernor) putCached(key string, v map[string]string, ttl time.Duration) {
	if ttl <= 0 || len(v) == 0 {
		return
	}
	g.mu.Lock()
	defer g.mu.Unlock()
	g.cache[key] = voiceCacheEntry{value: cloneStringMap(v), expiresAt: g.nowFn().Add(ttl)}
}
func (g *voiceQueryGovernor) hitCache() { g.mu.Lock(); defer g.mu.Unlock(); g.stats.cacheHits++ }
func (g *voiceQueryGovernor) markRateLimited() {
	g.mu.Lock()
	defer g.mu.Unlock()
	g.stats.rateLimited++
}
func (g *voiceQueryGovernor) observeLatency(ms int64) {
	g.mu.Lock()
	defer g.mu.Unlock()
	g.stats.latencySamples++
	g.stats.latencyTotalMs += ms
}
func (g *voiceQueryGovernor) snapshot() map[string]string {
	g.mu.Lock()
	defer g.mu.Unlock()
	avg := int64(0)
	if g.stats.latencySamples > 0 {
		avg = g.stats.latencyTotalMs / g.stats.latencySamples
	}
	return map[string]string{"query_rps": fmt.Sprintf("%.2f", g.cfg.ratePerSecond), "ban_events": strconv.FormatInt(g.stats.banEvents, 10), "retries": strconv.FormatInt(g.stats.retries, 10), "latency_avg_ms": strconv.FormatInt(avg, 10)}
}

func parseRetryAfter(v string) int {
	v = strings.TrimSpace(v)
	if v == "" {
		return 0
	}
	if n, err := strconv.Atoi(v); err == nil {
		if n < 0 {
			return 0
		}
		return n
	}
	if t, err := http.ParseTime(v); err == nil {
		return maxInt(1, int(time.Until(t).Seconds()))
	}
	return 0
}
func bytesContainsBan(payload []byte) bool {
	s := strings.ToLower(string(payload))
	return strings.Contains(s, "ban") || strings.Contains(s, "banned") || strings.Contains(s, "cooldown")
}
func cloneStringMap(src map[string]string) map[string]string {
	out := make(map[string]string, len(src))
	for k, v := range src {
		out[k] = v
	}
	return out
}
func mapFromKeys(src map[string]string, keys []string) map[string]string {
	out := map[string]string{}
	for _, k := range keys {
		if v := strings.TrimSpace(src[k]); v != "" {
			out[k] = v
		}
	}
	return out
}
func stringify(v any, fallback string) string {
	s := strings.TrimSpace(fmt.Sprintf("%v", v))
	if s == "" || s == "<nil>" {
		return fallback
	}
	return s
}
func retry(until, now time.Time) int { return maxInt(1, int(math.Ceil(until.Sub(now).Seconds()))) }
func envFloat(name string, fallback float64) float64 {
	return envFloatAny([]string{name}, fallback)
}
func envFloatAny(names []string, fallback float64) float64 {
	for _, name := range names {
		raw := strings.TrimSpace(os.Getenv(name))
		if raw == "" {
			continue
		}
		if n, err := strconv.ParseFloat(raw, 64); err == nil {
			return n
		}
	}
	return fallback
}
func envDurationAny(names []string, fallback time.Duration) time.Duration {
	for _, name := range names {
		raw := strings.TrimSpace(os.Getenv(name))
		if raw == "" {
			continue
		}
		if d, err := time.ParseDuration(raw); err == nil {
			return d
		}
		if n, err := strconv.Atoi(raw); err == nil {
			return time.Duration(n) * time.Second
		}
	}
	return fallback
}
func envDuration(name string, fallback time.Duration) time.Duration {
	return envDurationAny([]string{name}, fallback)
}

func envInt(name string, fallback int) int {
	if raw := strings.TrimSpace(os.Getenv(name)); raw != "" {
		if n, err := strconv.Atoi(raw); err == nil {
			return n
		}
	}
	return fallback
}
func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}
func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}
func minFloat(a, b float64) float64 {
	if a < b {
		return a
	}
	return b
}
