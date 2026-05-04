<?php
declare(strict_types=1);
namespace App\Module\Core\Application;

final class MailDnsCheckService
{
    public function __construct(private readonly DnsResolverInterface $resolver) {}

    public function check(string $domain, ?string $mailHost = null, ?string $dkimSelector = null): array
    {
        $mx = $this->resolver->query($domain, DNS_MX);
        $txt = $this->resolver->query($domain, DNS_TXT);
        $dmarc = $this->resolver->query('_dmarc.' . $domain, DNS_TXT);

        $spfOk = false;
        foreach ($txt as $r) {
            $v = strtolower((string)($r['txt'] ?? ''));
            if (str_contains($v, 'v=spf1')) { $spfOk = true; break; }
        }
        $dmarcOk = false;
        foreach ($dmarc as $r) {
            $v = strtolower((string)($r['txt'] ?? ''));
            if (str_contains($v, 'v=dmarc1')) { $dmarcOk = true; break; }
        }

        $dkimMessage = 'No DKIM selector configured'; $dkimOk = false;
        if ($dkimSelector !== null && $dkimSelector !== '') {
            $dkimRecords = $this->resolver->query($dkimSelector . '._domainkey.' . $domain, DNS_TXT);
            $dkimOk = $dkimRecords !== [];
            $dkimMessage = $dkimOk ? 'DKIM record present' : 'DKIM record missing';
        }

        $checks = [
            'mx' => ['ok' => $mx !== [], 'message' => $mx !== [] ? 'MX record present' : 'MX record missing'],
            'spf' => ['ok' => $spfOk, 'message' => $spfOk ? 'SPF record present' : 'SPF record missing'],
            'dmarc' => ['ok' => $dmarcOk, 'message' => $dmarcOk ? 'DMARC record present' : 'DMARC record missing'],
            'dkim' => ['ok' => $dkimOk, 'message' => $dkimMessage],
        ];
        if ($mailHost !== null && $mailHost !== '') {
            $a = $this->resolver->query($mailHost, DNS_A + DNS_AAAA);
            $checks['mailhost'] = ['ok' => $a !== [], 'message' => $a !== [] ? 'Mail host resolves' : 'Mail host missing A/AAAA'];
        }

        $overallOk = true;
        foreach (['mx','spf','dmarc'] as $k) { if (!$checks[$k]['ok']) { $overallOk = false; } }

        return ['domain' => $domain, 'overall_ok' => $overallOk, 'checks' => $checks];
    }
}
