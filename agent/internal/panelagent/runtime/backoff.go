package runtime

import (
    "math/rand"
    "time"
)

type Backoff struct {
    Base time.Duration
    Max  time.Duration
}

func (b Backoff) Next(attempt int) time.Duration {
    if attempt < 1 {
        attempt = 1
    }
    d := b.Base * time.Duration(1<<(attempt-1))
    if d > b.Max {
        d = b.Max
    }
    jitter := time.Duration(rand.Int63n(int64(d / 2)))
    return d/2 + jitter
}
