.PHONY: fmt lint test build

fmt:
	gofmt -w ./cmd ./internal

lint:
	go vet ./...

test:
	go test ./...

build:
	go build -trimpath -o bin/easywi-agent ./cmd/agent
