package logstream

import (
	"regexp"
	"strings"
	"time"
)

var (
	postfixDomainRe = regexp.MustCompile(`@([a-zA-Z0-9.-]+)`)
	queueIDRe       = regexp.MustCompile(`\b([A-F0-9]{5,})\b`)
)

func ParseLine(source string, line string, now time.Time) Event {
	lower := strings.ToLower(strings.TrimSpace(line))
	event := Event{
		CreatedAt: now.UTC(),
		Level:     "info",
		Source:    normalizeSource(source),
		EventType: "policy",
		Message:   strings.TrimSpace(line),
		Payload:   map[string]any{},
	}

	if strings.Contains(lower, "warning") {
		event.Level = "warning"
	}
	if strings.Contains(lower, "error") || strings.Contains(lower, "failed") {
		event.Level = "error"
	}
	if strings.Contains(lower, "critical") || strings.Contains(lower, "panic") {
		event.Level = "critical"
	}

	switch {
	case strings.Contains(lower, "auth") || strings.Contains(lower, "sasl login"):
		event.EventType = "auth"
	case strings.Contains(lower, "starttls") || strings.Contains(lower, "tls"):
		event.EventType = "tls"
	case strings.Contains(lower, "reject") || strings.Contains(lower, "spam"):
		event.EventType = "spam"
	case strings.Contains(lower, "bounce") || strings.Contains(lower, "status=bounced"):
		event.EventType = "bounce"
		if event.Level == "info" {
			event.Level = "error"
		}
	case strings.Contains(lower, "status=sent") || strings.Contains(lower, "delivered"):
		event.EventType = "delivery"
	case strings.Contains(lower, "queue"):
		event.EventType = "queue"
	case strings.Contains(lower, "dns"):
		event.EventType = "dns_check"
	}

	if match := postfixDomainRe.FindStringSubmatch(line); len(match) == 2 {
		event.Domain = strings.ToLower(strings.Trim(match[1], "."))
		event.Payload["domain_observed"] = event.Domain
	}
	if match := queueIDRe.FindStringSubmatch(strings.ToUpper(line)); len(match) == 2 {
		event.Payload["queue_id"] = match[1]
	}

	return event
}

func normalizeSource(raw string) string {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "postfix", "dovecot", "opendkim", "agent", "dns", "rspamd":
		return strings.ToLower(strings.TrimSpace(raw))
	default:
		return "agent"
	}
}
