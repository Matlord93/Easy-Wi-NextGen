package main

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

func ensureKVMRole(writeRole func(string, *strings.Builder) error) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 12*time.Minute)
	defer cancel()
	engine, err := newKVMEngine(ctx)
	if err != nil {
		return "", err
	}
	unlock, err := lockKVM(ctx, filepath.Join(engine.root, "host.lock"))
	if err != nil {
		return "", err
	}
	defer unlock()
	if _, err := engine.setupHost(); err != nil {
		return "", err
	}
	var output strings.Builder
	appendOutput(&output, "host_setup=ready")
	err = writeRole("kvm", &output)
	return output.String(), err
}

var kvmHostPreflight = func() error {
	family, err := detectOSFamily()
	if err != nil || family != "debian" {
		return errors.New("KVM host setup requires Debian or Ubuntu")
	}
	if _, err := exec.LookPath("pveversion"); err == nil || pathExists("/etc/pve") {
		return errors.New("use the Proxmox API to manage a Proxmox VE host")
	}
	if uri := os.Getenv("EASYWI_KVM_URI"); uri != "" && uri != "qemu:///system" {
		return errors.New("KVM host setup requires qemu:///system")
	}
	if !kvmAccelerationAvailable() {
		return errors.New("/dev/kvm is unavailable; enable hardware virtualization before setting up the host")
	}
	return nil
}

var kvmHostMissingTools = func() []string {
	missing := []string{}
	for _, binary := range []string{"qemu-system-x86_64", "virsh", "qemu-img", "cloud-localds"} {
		if _, err := exec.LookPath(binary); err != nil {
			missing = append(missing, binary)
		}
	}
	return missing
}

var kvmHostCanInstall = func() bool { return os.Geteuid() == 0 }

func (e *kvmEngine) setupHost() (map[string]any, error) {
	if err := kvmHostPreflight(); err != nil {
		return nil, err
	}
	if len(kvmHostMissingTools()) > 0 {
		if !kvmHostCanInstall() {
			return nil, errors.New("the agent needs root permissions to install the KVM host packages")
		}
		if _, err := e.command("apt-get", "update"); err != nil {
			return nil, err
		}
		if _, err := e.command("apt-get", "-o", "DPkg::Lock::Timeout=60", "install", "-y", "qemu-system-x86", "libvirt-daemon-system", "libvirt-clients", "qemu-utils", "cloud-image-utils"); err != nil {
			return nil, err
		}
	}
	if missing := kvmHostMissingTools(); len(missing) > 0 {
		return nil, errors.New("KVM host tools are unavailable: " + strings.Join(missing, ", "))
	}
	if _, err := e.virsh("list", "--all", "--uuid"); err != nil {
		if !kvmHostCanInstall() {
			return nil, err
		}
		// Modern installations may use per-driver daemons. Leave a working daemon as it is.
		unit := "libvirtd.socket"
		if state, probeErr := e.command("systemctl", "show", "--property=LoadState", "--value", unit); probeErr != nil || strings.TrimSpace(string(state)) != "loaded" {
			unit = "virtqemud.socket"
		}
		if _, err := e.command("systemctl", "enable", "--now", unit); err != nil {
			return nil, err
		}
	}
	if _, err := e.virsh("domcapabilities", "--virttype", "kvm"); err != nil {
		return nil, err
	}
	return map[string]any{"host_setup": "ready"}, nil
}
