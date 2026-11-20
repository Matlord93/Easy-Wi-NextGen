<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Registrar;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\AppSetting;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegistrarSettings
{
    private const KEY = 'autodns_registrar_options';

    public function __construct(private EntityManagerInterface $em, private SecretsCryptoInterface $crypto)
    {
    }

    /** @return array<string,int|string|bool> */
    public function publicSettings(): array
    {
        $stored = $this->stored();

        return ['enabled' => ($stored['enabled'] ?? false) === true, 'sandbox' => ($stored['sandbox'] ?? true) === true, 'context' => (int) ($stored['context'] ?? 4), 'tlds' => ScalarValue::text($stored['tlds'] ?? 'de,com,net,org'), 'nameservers' => ScalarValue::text($stored['nameservers'] ?? ''), 'admin_contact' => (int) ($stored['admin_contact'] ?? 0), 'tech_contact' => (int) ($stored['tech_contact'] ?? 0), 'zone_contact' => (int) ($stored['zone_contact'] ?? 0), 'user_configured' => !empty($stored['user']), 'password_configured' => !empty($stored['password'])];
    }

    public function identity(): string
    {
        return hash('sha256', $this->secret('user').':'.(int) $this->publicSettings()['context'].':'.(true === $this->publicSettings()['sandbox'] ? 'test' : 'live'));
    }

    public function secret(string $key): string
    {
        if (!in_array($key, ['user', 'password'], true)) {
            throw new \InvalidArgumentException('Unknown registrar credential.');
        }
        $value = ScalarValue::text($this->stored()[$key] ?? null);

        return '' === $value ? '' : $this->crypto->decrypt($value);
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): void
    {
        $stored = $this->stored();
        foreach (['user', 'password'] as $key) {
            $raw = ScalarValue::text($input[$key] ?? null);
            if (strlen($raw) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
                throw new \InvalidArgumentException('Ungültige Registrar-Zugangsdaten.');
            }
            if ('' !== $raw) {
                $stored[$key] = $this->crypto->encrypt($raw);
            }
            if (($input['clear_'.$key] ?? false) === true) {
                unset($stored[$key]);
            }
        }
        $stored['enabled'] = ($input['enabled'] ?? false) === true;
        $stored['sandbox'] = ($input['sandbox'] ?? true) === true;
        $context = filter_var($input['context'] ?? 4, FILTER_VALIDATE_INT);
        if (!is_int($context) || $context < 1 || $context > 2_147_483_647) {
            throw new \InvalidArgumentException('Ungültiger AutoDNS-Kontext.');
        }
        $stored['context'] = $context;
        $tlds = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower(ScalarValue::text($input['tlds'] ?? null)))))));
        if (count($tlds) < 1 || count($tlds) > 200) {
            throw new \InvalidArgumentException('Erlaubte Domainendungen hinterlegen.');
        }
        foreach ($tlds as $tld) {
            if (!preg_match('/^[a-z0-9-]{2,63}(?:\.[a-z0-9-]{2,63})?$/D', $tld)) {
                throw new \InvalidArgumentException('Ungültige Domainendung.');
            }
        }
        $stored['tlds'] = implode(',', $tlds);
        $ns = preg_split('/[\s,]+/', trim(ScalarValue::text($input['nameservers'] ?? null)), -1, PREG_SPLIT_NO_EMPTY);
        if (false === $ns) {
            throw new \InvalidArgumentException('Ungültige Nameserver.');
        }
        if (count($ns) < 2 || count($ns) > 10) {
            throw new \InvalidArgumentException('Zwei bis zehn Nameserver sind erforderlich.');
        }
        foreach ($ns as $name) {
            if (false === filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($name, '.')) {
                throw new \InvalidArgumentException('Ungültiger Nameserver.');
            }
        }
        $stored['nameservers'] = implode(',', $ns);
        foreach (['admin_contact', 'tech_contact', 'zone_contact'] as $key) {
            $id = filter_var($input[$key] ?? 0, FILTER_VALIDATE_INT);
            if (!is_int($id) || $id < 1 || $id > 2_147_483_647) {
                throw new \InvalidArgumentException('Vorhandene AutoDNS-Kontakte für Admin-C, Tech-C und Zone-C hinterlegen.');
            }
            $stored[$key] = $id;
        }
        if ($stored['enabled'] && (empty($stored['user']) || empty($stored['password']))) {
            throw new \InvalidArgumentException('AutoDNS-Benutzer und Passwort erforderlich.');
        }
        $setting = $this->em->find(AppSetting::class, self::KEY) ?? new AppSetting(self::KEY, []);
        $setting->setValue($stored);
        $this->em->persist($setting);
        $this->em->flush();
    }

    /** @return array<string,mixed> */
    private function stored(): array
    {
        return JsonMap::fromValue($this->em->find(AppSetting::class, self::KEY)?->getValue()) ?? [];
    }
}
