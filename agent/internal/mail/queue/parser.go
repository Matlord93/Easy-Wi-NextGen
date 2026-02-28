package queue

import "strings"

type Summary struct {
	Depth    int
	Deferred int
}

func ParsePostqueue(output string) Summary {
	lines := strings.Split(output, "\n")
	s := Summary{}
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		s.Depth++
		if strings.Contains(strings.ToLower(line), "deferred") {
			s.Deferred++
		}
	}
	return s
}
