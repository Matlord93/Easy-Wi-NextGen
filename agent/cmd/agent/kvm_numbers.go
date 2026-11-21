package main

import (
	"errors"
	"os"
	"path/filepath"
)

type kvmNumberRange struct {
	First int `json:"first"`
	Last  int `json:"last"`
}

func (e *kvmEngine) numberRange() (kvmNumberRange, error) {
	var span kvmNumberRange
	err := kvmReadJSON(filepath.Join(e.root, "vm-number-range.json"), &span)
	if os.IsNotExist(err) {
		return span, nil
	}
	if err == nil && span != (kvmNumberRange{}) && (span.First < 100 || span.Last > 999999999 || span.First > span.Last) {
		err = errors.New("invalid saved VM number range")
	}
	return span, err
}

func (e *kvmEngine) configureNumbers(p map[string]any) (map[string]any, error) {
	var span kvmNumberRange
	if payloadValue(p, "first") != "" || payloadValue(p, "last") != "" {
		first, err := positivePayloadInt(p, "first", 100, 999999999)
		if err != nil {
			return nil, err
		}
		last, err := positivePayloadInt(p, "last", first, 999999999)
		if err != nil {
			return nil, err
		}
		span = kvmNumberRange{First: first, Last: last}
	}
	return map[string]any{"vm_number_range": span}, kvmWriteJSON(filepath.Join(e.root, "vm-number-range.json"), span)
}

// The host lock is held by the caller until the guest manifest is committed.
func (e *kvmEngine) chooseNumber(p map[string]any) (int, error) {
	span, err := e.numberRange()
	if err != nil {
		return 0, err
	}
	first, last := span.First, span.Last
	manual := payloadValue(p, "vm_number") != ""
	if manual {
		var err error
		first, err = positivePayloadInt(p, "vm_number", 100, 999999999)
		if err != nil {
			return 0, err
		}
		if span.First != 0 && (first < span.First || first > span.Last) {
			return 0, errors.New("VM number is outside this host's range")
		}
		last = first
	}
	if first == 0 {
		return 0, nil
	}
	used := make(map[int]bool)
	if err := e.eachGuestManifest(func(guest kvmGuest) error {
		used[guest.Number] = true
		return nil
	}); err != nil {
		return 0, err
	}
	for number := first; number <= last; number++ {
		if !used[number] {
			return number, nil
		}
	}
	if manual {
		return 0, errors.New("VM number is already assigned on this host")
	}
	return 0, errors.New("no free VM number in this host's range")
}

func (e *kvmEngine) eachGuestManifest(visit func(kvmGuest) error) error {
	entries, err := os.ReadDir(filepath.Join(e.root, "instances"))
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if !kvmUUID.MatchString(entry.Name()) {
			continue
		}
		var guest kvmGuest
		err := kvmReadJSON(e.guestFile(entry.Name(), "manifest.json"), &guest)
		if os.IsNotExist(err) {
			continue
		}
		if err != nil || guest.ID != entry.Name() {
			return errors.New("cannot verify the existing VM manifests")
		}
		if err := visit(guest); err != nil {
			return err
		}
	}
	return nil
}
