<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Registrar;

use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\RegistrarOperation;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\User;
use App\Repository\CustomerProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DomainRegistrarQueue
{
    public function __construct(private EntityManagerInterface $em, private RegistrarSettings $settings, private AutodnsClient $client, private SecretsCryptoInterface $crypto, private CustomerProfileRepository $profiles, private CacheInterface $cache)
    {
    }

    /** @param array<string,int|string|bool> $quote */
    public function validate(User $customer, array $quote): void
    {
        if (($quote['registrar'] ?? '') !== 'autodns') {
            return;
        }
        $settings = $this->settings->publicSettings();
        $tld = (string) ($quote['tld'] ?? '');
        if (true !== $settings['enabled'] || '' === $tld || !in_array($tld, explode(',', (string) $settings['tlds']), true) || !str_ends_with((string) $quote['hostname'], '.'.$tld)) {
            throw new \DomainException('Dieses Domainprodukt ist für AutoDNS derzeit nicht freigegeben.');
        }
        $profile = $this->profiles->findOneByCustomer($customer);
        if (null === $profile || '' === trim($profile->getFirstName()) || '' === trim($profile->getLastName()) || '' === trim($profile->getAddress()) || '' === trim($profile->getPostal()) || '' === trim($profile->getCity()) || !preg_match('/^[A-Z]{2}$/D', strtoupper($profile->getCountry())) || !preg_match('/^\+[0-9][0-9 .()-]{5,30}$/D', $profile->getPhone() ?? '')) {
            throw new \DomainException('Für eine Domainregistrierung vollständige Kontaktdaten einschließlich internationaler Telefonnummer im Kundenprofil hinterlegen.');
        }
        if (($quote['domain_action'] ?? 'register') === 'register' && !$this->available((string) $quote['hostname'])) {
            throw new \DomainException('Die Domain ist nicht frei oder benötigt eine gesonderte Preisprüfung.');
        }
    }

    public function available(string $domain): bool
    {
        $result = $this->cache->get('autodns.available.'.hash('sha256', $this->settings->identity().':'.$domain), function (ItemInterface $item) use ($domain): bool {
            $item->expiresAfter(60);

            return $this->client->available($domain);
        });

        return true === $result;
    }

    public function enqueue(ShopContract $contract, string $action, string $authinfo = ''): void
    {
        if (($contract->getConfiguration()['registrar'] ?? '') !== 'autodns') {
            return;
        }
        $period = $contract->getTermEndsAt()?->format('Y-m-d') ?? 'initial';
        $key = \App\Infrastructure\Doctrine\EntityIdentifier::require($contract->getId()).':'.$action.':'.$period;
        if (null !== $this->em->getRepository(RegistrarOperation::class)->findOneBy(['requestKey' => $key])) {
            return;
        }
        $this->em->persist(new RegistrarOperation($contract, $action, $key, $this->settings->identity(), '' !== $authinfo ? $this->crypto->encrypt($authinfo) : null));
    }
}
