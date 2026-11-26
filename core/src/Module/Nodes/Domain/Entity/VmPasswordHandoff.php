<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

trait VmPasswordHandoff
{
    /** @var array{operation: string, expires_at: int, ciphertext: string}|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rootPasswordHandoff = null;

    /** @return array{operation: string, expires_at: int, ciphertext: string}|null */
    public function getRootPasswordHandoff(): ?array
    {
        return $this->rootPasswordHandoff;
    }

    /** @param array{operation: string, expires_at: int, ciphertext: string}|null $handoff */
    public function setRootPasswordHandoff(?array $handoff): void
    {
        $this->rootPasswordHandoff = $handoff;
    }
}
