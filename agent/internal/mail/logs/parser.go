package logs

import "strings"

type Event struct {
	Level   string
	Source  string
	Message string
}

func ParseLine(line string) Event {
	l := strings.ToLower(line)
	level := "info"
	if strings.Contains(l, "error") || strings.Contains(l, "failed") {
		level = "error"
	}
	source := "agent"
	switch {
	case strings.Contains(l, "postfix"):
		source = "postfix"
	case strings.Contains(l, "dovecot"):
		source = "dovecot"
	case strings.Contains(l, "opendkim"):
		source = "opendkim"
	}
	return Event{Level: level, Source: source, Message: strings.TrimSpace(line)}
}
