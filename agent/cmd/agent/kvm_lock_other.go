//go:build !linux

package main

import (
	"context"
	"errors"
)

func lockKVM(context.Context, string) (func(), error) {
	return nil, errors.New("managed KVM requires Linux")
}
