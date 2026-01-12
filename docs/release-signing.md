# Release Signing

Easy-Wi NextGen releases are published with SHA256 checksums and detached GPG signatures. Installers and the agent verify signatures before trusting checksums.

## Public Key

The public key used to verify releases is stored in:

- `docs/release-signing-public.key`

Fingerprint:

```
344B FDB8 2BD3 4A21 CDD6  3B81 A39B C66A 8863 AE38
```

## CI Signing

CI signs the checksum files (`checksums-*.txt`) during tagged releases.

Required secrets:

- `EASYWI_GPG_PRIVATE_KEY` — ASCII-armored private key for the release signing key.
- `EASYWI_GPG_PASSPHRASE` — Passphrase for the private key (empty if unencrypted).

CI imports the private key into a temporary keyring and generates detached signatures (`checksums-*.txt.asc`).

## Verifying Manually

1. Download the release assets plus the checksum signature.
2. Import the public key:

```bash
gpg --import docs/release-signing-public.key
```

3. Verify the checksum signature:

```bash
gpg --verify checksums-core.txt.asc checksums-core.txt
```

4. Verify the checksum for the asset you downloaded:

```bash
sha256sum -c checksums-core.txt
```

## Runtime Verification

- **Agent self-update** downloads the checksums file plus `checksums-*.txt.asc` and verifies the signature before trusting SHA256 values.
- **Installer** downloads the checksums file plus `checksums-*.txt.asc` and verifies the signature before installing or upgrading.

If the signature is missing or invalid, the update/installation is aborted.
