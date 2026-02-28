package logstream

import (
	"context"
	"time"

	panelapi "easywi/agent/internal/panelagent/api"
)

type BatchPoster interface {
	PostMailLogsBatch(ctx context.Context, req panelapi.MailLogsBatchRequest) error
}

func SendBatch(ctx context.Context, client BatchPoster, events []Event) error {
	if len(events) == 0 {
		return nil
	}

	payload := panelapi.MailLogsBatchRequest{Events: make([]panelapi.MailLogEvent, 0, len(events))}
	for _, event := range events {
		createdAt := event.CreatedAt
		if createdAt.IsZero() {
			createdAt = time.Now().UTC()
		}
		payload.Events = append(payload.Events, panelapi.MailLogEvent{
			CreatedAt: createdAt.UTC().Format(time.RFC3339),
			Level:     event.Level,
			Source:    event.Source,
			Domain:    event.Domain,
			UserID:    event.UserID,
			EventType: event.EventType,
			Message:   event.Message,
			Payload:   event.Payload,
		})
	}

	return client.PostMailLogsBatch(ctx, payload)
}
