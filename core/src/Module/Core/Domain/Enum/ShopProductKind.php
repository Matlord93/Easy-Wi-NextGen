<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum ShopProductKind: string
{
    case Gameserver = 'gameserver';
    case Teamspeak3 = 'teamspeak3';
    case Teamspeak6 = 'teamspeak6';
    case Kvm = 'kvm';
    case Proxmox = 'proxmox';
    case Webspace = 'webspace';
    case Domain = 'domain';

    public function label(): string
    {
        return match ($this) {
            self::Gameserver => 'Gameserver', self::Teamspeak3 => 'TeamSpeak 3', self::Teamspeak6 => 'TeamSpeak 6',
            self::Kvm => 'KVM-Server', self::Proxmox => 'Proxmox-Server', self::Webspace => 'Webhosting', self::Domain => 'Domain',
        };
    }

    public function isVirtualMachine(): bool
    {
        return in_array($this, [self::Kvm, self::Proxmox], true);
    }
}
