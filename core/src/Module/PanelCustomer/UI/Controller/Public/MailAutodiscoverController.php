<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Repository\MailDomainRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves Outlook Autodiscover (v1) and Thunderbird Autoconfig XML for mail client setup.
 */
final class MailAutodiscoverController
{
    public function __construct(
        private readonly MailDomainRepository $mailDomainRepository,
    ) {
    }

    /**
     * Outlook Autodiscover v1 – Outlook POSTs an XML body containing the user's email.
     */
    #[Route(path: '/autodiscover/autodiscover.xml', name: 'mail_autodiscover_outlook', methods: ['GET', 'POST'])]
    public function autodiscover(Request $request): Response
    {
        $email = $this->extractEmailFromOutlookXml($request->getContent());
        if ($email === null) {
            $email = (string) $request->query->get('email', '');
        }

        $node = $this->resolveNodeForEmail($email);

        $displayName = $node !== null ? $node->getImapHost() : 'mail';
        $imapHost    = $node?->getImapHost() ?? '';
        $imapPort    = $node?->getImapPort() ?? 993;
        $smtpHost    = $node?->getSmtpHost() ?? '';
        $smtpPort    = $node?->getSmtpPort() ?? 587;

        // Determine SSL/STARTTLS based on conventional ports.
        $imapSsl  = $imapPort === 993 ? 'SSL' : 'STARTTLS';
        $smtpAuth = $smtpPort === 465 ? 'SSL' : 'STARTTLS';

        $xml = $this->buildAutodiscoverXml($email, $displayName, $imapHost, $imapPort, $imapSsl, $smtpHost, $smtpPort, $smtpAuth);

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * Thunderbird Autoconfig – GET request with ?emailaddress=user@domain.tld
     */
    #[Route(path: '/.well-known/autoconfig/mail/config-v1.1.xml', name: 'mail_autoconfig_thunderbird', methods: ['GET'])]
    public function autoconfig(Request $request): Response
    {
        $email = (string) $request->query->get('emailaddress', '');
        $node  = $this->resolveNodeForEmail($email);
        $domain = $this->domainFromEmail($email);
        if ($domain === null || $node === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $imapHost = $node->getImapHost() !== '' ? $node->getImapHost() : sprintf('mail.%s', $domain);
        $imapPort = $node?->getImapPort() ?? 993;
        $smtpHost = $node->getSmtpHost() !== '' ? $node->getSmtpHost() : sprintf('mail.%s', $domain);
        $smtpPort = $node?->getSmtpPort() ?? 587;

        $imapSocketType = $imapPort === 993 ? 'SSL' : 'STARTTLS';
        $smtpSocketType = $smtpPort === 465 ? 'SSL' : 'STARTTLS';

        $xml = $this->buildAutoconfigXml($domain, $imapHost, $imapPort, $imapSocketType, $smtpHost, $smtpPort, $smtpSocketType);

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function resolveNodeForEmail(string $email): ?\App\Module\Core\Domain\Entity\MailNode
    {
        $domainPart = $this->domainFromEmail($email);
        if ($domainPart === null) {
            return null;
        }

        $mailDomain = $this->mailDomainRepository->findOneByDomainName($domainPart);

        return $mailDomain?->getNode();
    }

    private function domainFromEmail(string $email): ?string
    {
        $atPos = strrpos($email, '@');
        if ($atPos === false) {
            return null;
        }

        $domain = substr($email, $atPos + 1);
        return $domain !== '' ? strtolower(trim($domain)) : null;
    }

    private function extractEmailFromOutlookXml(string $body): ?string
    {
        if (trim($body) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOENT);
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $nodes = $doc->getElementsByTagNameNS('*', 'EMailAddress');
        if ($nodes->length === 0) {
            $nodes = $doc->getElementsByTagName('EMailAddress');
        }

        if ($nodes->length > 0) {
            $value = trim($nodes->item(0)?->textContent ?? '');
            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function buildAutodiscoverXml(
        string $email,
        string $displayName,
        string $imapHost,
        int $imapPort,
        string $imapSsl,
        string $smtpHost,
        int $smtpPort,
        string $smtpAuth,
    ): string {
        $email       = htmlspecialchars($email, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $displayName = htmlspecialchars($displayName, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $imapHost    = htmlspecialchars($imapHost, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $smtpHost    = htmlspecialchars($smtpHost, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $imapSsl     = htmlspecialchars($imapSsl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $smtpAuth    = htmlspecialchars($smtpAuth, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">
    <Account>
      <AccountType>email</AccountType>
      <Action>settings</Action>
      <Protocol>
        <Type>IMAP</Type>
        <Server>{$imapHost}</Server>
        <Port>{$imapPort}</Port>
        <LoginName>{$email}</LoginName>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <SSL>{$imapSsl}</SSL>
        <AuthRequired>on</AuthRequired>
      </Protocol>
      <Protocol>
        <Type>SMTP</Type>
        <Server>{$smtpHost}</Server>
        <Port>{$smtpPort}</Port>
        <LoginName>{$email}</LoginName>
        <DomainRequired>off</DomainRequired>
        <SPA>off</SPA>
        <Encryption>{$smtpAuth}</Encryption>
        <AuthRequired>on</AuthRequired>
        <UsePOPNoSend>off</UsePOPNoSend>
      </Protocol>
    </Account>
  </Response>
</Autodiscover>
XML;
    }

    private function buildAutoconfigXml(
        string $domain,
        string $imapHost,
        int $imapPort,
        string $imapSocketType,
        string $smtpHost,
        int $smtpPort,
        string $smtpSocketType,
    ): string {
        $domain         = htmlspecialchars($domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $imapHost       = htmlspecialchars($imapHost, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $smtpHost       = htmlspecialchars($smtpHost, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $imapSocketType = htmlspecialchars($imapSocketType, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $smtpSocketType = htmlspecialchars($smtpSocketType, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<clientConfig version="1.1">
  <emailProvider id="{$domain}">
    <domain>{$domain}</domain>
    <incomingServer type="imap">
      <hostname>{$imapHost}</hostname>
      <port>{$imapPort}</port>
      <socketType>{$imapSocketType}</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <incomingServer type="pop3">
      <hostname>{$imapHost}</hostname>
      <port>995</port>
      <socketType>SSL</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </incomingServer>
    <outgoingServer type="smtp">
      <hostname>{$smtpHost}</hostname>
      <port>{$smtpPort}</port>
      <socketType>{$smtpSocketType}</socketType>
      <authentication>password-cleartext</authentication>
      <username>%EMAILADDRESS%</username>
    </outgoingServer>
  </emailProvider>
</clientConfig>
XML;
    }
}
