package logging

import (
    "encoding/json"
    "log"
    "os"
    "time"
)

type Entry struct {
    Level   string                 `json:"level"`
    Message string                 `json:"message"`
    Fields  map[string]interface{} `json:"fields,omitempty"`
    At      string                 `json:"at"`
}

type Logger struct{ l *log.Logger }

func New() *Logger {
    return &Logger{l: log.New(os.Stdout, "", 0)}
}

func (l *Logger) Log(level, msg string, fields map[string]interface{}) {
    entry := Entry{Level: level, Message: msg, Fields: fields, At: time.Now().UTC().Format(time.RFC3339Nano)}
    payload, _ := json.Marshal(entry)
    l.l.Println(string(payload))
}
