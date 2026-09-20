<?php
declare(strict_types=1);
namespace App\Tests\Module\Core\Domain;
use App\Module\Core\Domain\Entity\NotificationPreference;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;
final class NotificationPreferenceTest extends TestCase
{
    public function testChannelsCanBeUpdatedIndependently(): void
    {
        $preference = new NotificationPreference(new User('user@example.test', UserType::Customer), 'billing');
        $preference->update(false, true, true);
        self::assertFalse($preference->isInAppEnabled());
        self::assertTrue($preference->isEmailEnabled());
        self::assertTrue($preference->isWebhookEnabled());
    }
    public function testRejectsInvalidCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NotificationPreference(new User('user@example.test', UserType::Customer), '../admin');
    }
}
