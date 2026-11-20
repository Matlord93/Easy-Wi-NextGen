<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Infrastructure\Config\ScalarValue;
use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Registrar\AutodnsClient;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class CustomerDomainTransferController
{
    public function __construct(private EntityManagerInterface $em, private AutodnsClient $client, private AuditLoggerInterface $audit, private CsrfTokenManagerInterface $csrf, private \App\Module\Core\Application\Registrar\RegistrarSettings $settings)
    {
    }

    #[Route('/marketplace/contracts/{id}/transfer-code', name: 'customer_domain_transfer_code', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function code(Request $request, int $id): Response
    {
        $actor = RequestActor::user($request);
        $contract = $this->em->find(ShopContract::class, $id);
        if (null === $actor || !$actor->isCustomer() || null === $contract || $contract->getCustomer()->getId() !== $actor->getId() || ShopProductKind::Domain !== $contract->getKind() || ($contract->getConfiguration()['registrar'] ?? '') !== 'autodns' || 'active' !== $contract->getStatus()) {
            return new Response('Not found.', 404);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken('domain-transfer-'.$id, $request->request->getString('_token')))) {
            return new Response('Invalid CSRF token.', 403);
        }
        try {
            $domain = AutodnsClient::first($this->client->call('GET', '/domain/'.(string) $contract->getConfiguration()['hostname']));
            $contact = $this->em->getRepository(\App\Module\Core\Domain\Entity\RegistrarContact::class)->findOneBy(['customer' => $actor, 'identity' => $this->settings->identity()]);
            if (null === $contact || (int) (\App\Infrastructure\Config\JsonMap::fromValue($domain['ownerc'] ?? null)['id'] ?? 0) !== $contact->getContactId()) {
                return new Response('Der Domaininhaber konnte beim Registrar nicht bestätigt werden.', 409);
            }
            $code = ScalarValue::text($domain['authinfo'] ?? null);
            if ('' === $code) {
                $created = AutodnsClient::first($this->client->call('POST', '/domain/'.(string) $contract->getConfiguration()['hostname'].'/_authinfo1'));
                $code = ScalarValue::text($created['authinfo'] ?? null);
            }
            if ('' === $code) {
                return new Response('Der Registrar stellt derzeit keinen Authcode bereit. Bitte den Betreiber kontaktieren.', 409);
            }
            $this->audit->log($actor, 'registrar.authinfo.accessed', ['contract_id' => $id]);
            $this->em->flush();

            return new Response($code, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
        } catch (\Throwable $error) {
            return new Response('Der Authcode konnte nicht abgerufen werden. Bitte den Betreiber kontaktieren.', 503);
        }
    }
}
