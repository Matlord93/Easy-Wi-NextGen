<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Domain\Entity\AppSetting;
use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RentalSettings
{
    private const KEY = 'rental_runtime_options';
    private const DEFAULTS = ['provisioning_minutes' => 120, 'max_attempts' => 3, 'retry_minutes' => 5, 'stop_attempts' => 10, 'data_retention_days' => 30, 'backup_retention_days' => 30, 'cleanup_enabled' => false, 'low_wallet_cents' => 500];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array<string,int|bool> */
    public function get(): array
    {
        $stored = JsonMap::fromValue($this->em->find(AppSetting::class, self::KEY)?->getValue()) ?? [];
        $result = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (is_int($default)) {
                if (is_int($stored[$key] ?? null)) {
                    $result[$key] = (int) $stored[$key];
                }
            } else {
                $result[$key] = true === ($stored[$key] ?? $default);
            }
        }

        return $result;
    }

    public function dataRetentionDays(ShopProductKind $kind): int
    {
        $settings = $this->get();
        $days = (int) $settings['data_retention_days'];

        // Custom Game/Web backup destinations can be inside the resource directory.
        return in_array($kind, [ShopProductKind::Gameserver, ShopProductKind::Webspace], true)
            ? max($days, (int) $settings['backup_retention_days']) : $days;
    }

    /** @param array<string,int|bool> $input */
    public function save(array $input): void
    {
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $input[$key] ?? $default;
            if ('cleanup_enabled' === $key) {
                $result[$key] = true === $value;
                continue;
            }
            $max = match ($key) {
                'low_wallet_cents' => 1_000_000, 'data_retention_days','backup_retention_days' => 3650, 'provisioning_minutes' => 10080, 'max_attempts','stop_attempts' => 20, default => 60,
            };
            if (!is_int($value) || $value < 1 || $value > $max) {
                throw new \InvalidArgumentException('Ungültige Mietvertrags-Einstellung: '.$key);
            }
            $result[$key] = $value;
        }
        $setting = $this->em->find(AppSetting::class, self::KEY) ?? new AppSetting(self::KEY, []);
        $setting->setValue($result);
        $this->em->persist($setting);
        $this->em->flush();
    }
}
