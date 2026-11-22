<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Ticket;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;

final class CustomerTicketCreationHttpTest extends AbstractWebTestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function testCreationReturnsToTheOverviewWithAOneTimeConfirmation(bool $htmx): void
    {
        $this->seedSite();
        $client = self::createClient();
        $customer = $this->loginAsRole($client, 'customer');
        $crawler = $client->request('GET', '/tickets?status=closed&lang=de');
        self::assertResponseIsSuccessful();
        $token = $crawler->filterXPath('//*[@id="ticket-form"]//input[@name="_token"]')->attr('value');
        $headers = $htmx ? ['HTTP_HX_REQUEST' => 'true'] : [];
        $subject = $htmx ? 'Supportanfrage per HTMX' : 'Supportanfrage per Formular';
        $form = ['_token' => $token, 'subject' => $subject, 'category' => 'technical', 'priority' => 'normal'];

        $client->request('POST', '/tickets', $form, server: $headers);
        self::assertResponseStatusCodeSame(400);
        self::assertResponseNotHasHeader('HX-Redirect');
        self::assertStringContainsString('Die Nachricht darf nicht leer sein.', $client->getResponse()->getContent());

        $client->request('POST', '/tickets', $form + ['message' => 'Ich benötige Hilfe.'], server: $headers);
        if ($htmx) {
            self::assertResponseStatusCodeSame(204);
            self::assertResponseHeaderSame('HX-Redirect', '/tickets');
        } else {
            self::assertResponseRedirects('/tickets', 303);
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $ticket = $manager->getRepository(Ticket::class)->findOneBy(['customer' => $customer->getId(), 'subject' => $subject]);
        self::assertInstanceOf(Ticket::class, $ticket);
        $ticketUrl = '/tickets/'.$ticket->getId();
        $confirmation = 'Dein Ticket #'.$ticket->getId().' wurde erfolgreich erstellt.';

        $crawler = $htmx ? $client->request('GET', '/tickets') : $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($confirmation, $crawler->filterXPath('//*[@role="status" and contains(@class,"ng-alert--success")]')->text());
        self::assertStringContainsString($subject, $crawler->filterXPath('//*[@id="tickets-table"]//a[@href="'.$ticketUrl.'"]')->text());

        $client->request('GET', '/tickets');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($confirmation, $client->getResponse()->getContent());
    }
}
