<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Model;

final readonly class VoiceUser
{
    public function __construct(
        private string $id,
        private string $nickname,
        private ?string $channelId = null,
        private ?string $token = null,
    ) {
    }

    public function id(): string { return $this->id; }
    public function nickname(): string { return $this->nickname; }
    public function channelId(): ?string { return $this->channelId; }
    public function token(): ?string { return $this->token; }
}
