<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use PHPUnit\Framework\TestCase;

final class InstanceSftpCredentialEntityTest extends TestCase
{
    public function testSetUsernameUpdatesToAgentProvidedValue(): void
    {
        $instance = $this->createMock(Instance::class);
        $credential = new InstanceSftpCredential($instance, 'sftp1', [
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ]);

        $credential->setUsername('gs_1_2');

        self::assertSame('gs_1_2', $credential->getUsername());
    }

    public function testSetUsernameIgnoresEmptyInput(): void
    {
        $instance = $this->createMock(Instance::class);
        $credential = new InstanceSftpCredential($instance, 'sftp1', [
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ]);

        $credential->setUsername('   ');

        self::assertSame('sftp1', $credential->getUsername());
    }
}
