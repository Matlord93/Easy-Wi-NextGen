//go:build windows

package main

func chownToInstanceOwner(_ string, _ string) error {
	return nil
}
