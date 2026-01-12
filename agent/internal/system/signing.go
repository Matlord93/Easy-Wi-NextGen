package system

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
)

const releasePublicKey = `-----BEGIN PGP PUBLIC KEY BLOCK-----

mDMEaWTbehYJKwYBBAHaRw8BAQdAaRJmIoen1V9S7W0VIMtRyPHANpidDa3ZFGI4
iL+0Itq0KkVhc3lXSSBSZWxlYXNlIChDSSkgPHJlbGVhc2VAZWFzeXdpLmxvY2Fs
PoiTBBMWCgA7FiEENEv9uCvTSiHN1juBo5vGaohjrjgFAmlk23oCGwMFCwkIBwIC
IgIGFQoJCAsCBBYCAwECHgcCF4AACgkQo5vGaohjrjjsVgEAv798sRczk7V3+JJx
eIsDaLuoPNLnmgjJJBTeDTWi4BcBAIri1gp6vLUQPEvztlsR3C0TrA+AcM3wFybh
TqhxmAUEuDgEaWTbehIKKwYBBAGXVQEFAQEHQN3j5y6phFmYpm7aSyzCyEDMv4hk
fp0lazlpiLEVu+5fAwEIB4h4BBgWCgAgFiEENEv9uCvTSiHN1juBo5vGaohjrjgF
Amlk23oCGwwACgkQo5vGaohjrjiJEgD/a6hao11MEXFSq0tViwKk47LDh+cbMlus
AGv4kekSZQ4BAPNk4zhcPAn0y7b6Kq4D/a6ZP5ffUgw391Ce4Dtxm7EO
=zglq
-----END PGP PUBLIC KEY BLOCK-----`

func verifyChecksumsSignature(checksumsPath, signaturePath string) error {
	if _, err := exec.LookPath("gpg"); err != nil {
		return fmt.Errorf("gpg not available for signature verification")
	}

	gpgHome, err := os.MkdirTemp("", "easywi-agent-gpg-")
	if err != nil {
		return fmt.Errorf("create gpg home: %w", err)
	}
	defer os.RemoveAll(gpgHome)

	keyPath := filepath.Join(gpgHome, "release-public.key")
	if err := os.WriteFile(keyPath, []byte(releasePublicKey), 0o600); err != nil {
		return fmt.Errorf("write signing key: %w", err)
	}

	importCmd := exec.Command("gpg", "--batch", "--homedir", gpgHome, "--import", keyPath)
	if err := importCmd.Run(); err != nil {
		return fmt.Errorf("import signing key: %w", err)
	}

	verifyCmd := exec.Command("gpg", "--batch", "--homedir", gpgHome, "--verify", signaturePath, checksumsPath)
	if err := verifyCmd.Run(); err != nil {
		return fmt.Errorf("verify checksum signature: %w", err)
	}

	return nil
}
