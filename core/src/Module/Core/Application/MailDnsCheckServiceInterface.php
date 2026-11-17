<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

interface MailDnsCheckServiceInterface
{
    /**
     * @return array{domain:string,overall_ok:bool,checks:array{
     * mx:array{ok:bool,message:string},spf:array{ok:bool,message:string},
     * dmarc:array{ok:bool,message:string},dkim:array{ok:bool,message:string},
     * mailhost?:array{ok:bool,message:string}}}
     */
    public function check(string $domain, ?string $mailHost = null, ?string $dkimSelector = null): array;
}
