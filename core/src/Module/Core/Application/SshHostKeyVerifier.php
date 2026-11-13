<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Application\Exception\SftpException;
use App\Module\Core\Domain\ValueObject\SshHostFingerprint;
use phpseclib3\Net\SFTP;

final class SshHostKeyVerifier
{
    public function requireFingerprint(?string $fingerprint): string
    {
        try {
            return SshHostFingerprint::normalize($fingerprint ?? '');
        } catch (\InvalidArgumentException $error) {
            throw new SftpException('sftp_host_key_untrusted', 'The SFTP host key must be trusted by an administrator before connecting.', 422, previous: $error);
        }
    }

    /** Must run after key exchange and before any login credentials are sent. */
    public function verify(SFTP $connection, ?string $fingerprint): void
    {
        $expected = $this->requireFingerprint($fingerprint);
        try {
            $key = $connection->getServerPublicHostKey();
        } catch (\Throwable $error) {
            throw new SftpException('sftp_host_key_unavailable', 'Unable to verify the SFTP host identity.', 502, previous: $error);
        }
        $parts = is_string($key) ? explode(' ', $key, 3) : [];
        $binary = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if (false === $binary || '' === $binary) {
            throw new SftpException('sftp_host_key_unavailable', 'Unable to verify the SFTP host identity.', 502);
        }
        $actual = 'SHA256:'.rtrim(base64_encode(hash('sha256', $binary, true)), '=');
        if (!hash_equals($expected, $actual)) {
            throw new SftpException('sftp_host_key_mismatch', 'The SFTP host identity does not match the trusted key.', 502);
        }
    }
}
