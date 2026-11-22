<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\Notification;
use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketMessage;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketCategory;
use App\Module\Core\Domain\Enum\TicketPriority;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class TicketReplyHttpTest extends AbstractWebTestCase
{
    public function testAdminReplyReturnsTheSavedMessageAndNotifiesTheCustomer(): void
    {
        $this->seedSite();
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $customer = $this->createUser('ticket-customer@example.test', UserType::Customer);
        $id = $this->createTicket($customer);

        $crawler = $client->request('GET', '/admin/tickets/'.$id.'?lang=de');
        self::assertResponseIsSuccessful();
        $token = $crawler->filterXPath('//form[@class="tkt-reply-form"]//input[@name="_token"]')->attr('value');
        $url = '/admin/tickets/'.$id.'/messages';
        $hx = ['HTTP_HX_REQUEST' => 'true'];

        $client->request('POST', $url, ['_token' => $token, 'message' => '   '], server: $hx);
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('X-EasyWi-Ticket-Validation', '1');
        self::assertSame('Die Nachricht darf nicht leer sein.', $client->getResponse()->getContent());

        $oversized = new UploadedFile('', 'oversized.txt', 'text/plain', error: UPLOAD_ERR_INI_SIZE, test: true);
        $client->request('POST', $url, ['_token' => $token, 'message' => 'Draft reply'], ['attachments' => $oversized], $hx);
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('X-EasyWi-Ticket-Validation', '1');
        self::assertStringContainsString('höchstens 10 MB', $client->getResponse()->getContent());
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(TicketMessage::class)->count(['ticket' => $id]));

        $crawler = $client->request('POST', $url, ['_token' => $token, 'message' => 'The issue has been resolved.'], server: $hx);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The issue has been resolved.', $crawler->filterXPath('//*[@id="ticket-messages"]')->text());
        $this->assertReplyWasRecorded($id, $customer, 'The issue has been resolved.');

        $client->request('POST', $url, ['_token' => 'invalid', 'message' => 'Ignore this']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(TicketMessage::class)->count(['ticket' => $id]));
    }

    public function testCustomerCanSendConsecutiveRepliesAndUseTheApi(): void
    {
        $this->seedSite();
        $client = self::createClient();
        $customer = $this->loginAsRole($client, 'customer');
        $admin = $this->createUser('ticket-admin@example.test', UserType::Admin);
        $managedCustomer = self::getContainer()->get(EntityManagerInterface::class)->find(User::class, $customer->getId());
        self::assertInstanceOf(User::class, $managedCustomer);
        $id = $this->createTicket($managedCustomer);

        $crawler = $client->request('GET', '/tickets/'.$id);
        self::assertResponseIsSuccessful();
        $token = $crawler->filterXPath('//form[@data-ticket-reply]//input[@name="_token"]')->attr('value');
        $url = '/tickets/'.$id.'/messages';
        foreach (['First test reply', 'Second test reply'] as $message) {
            $crawler = $client->request('POST', $url, ['_token' => $token, 'message' => $message], server: ['HTTP_HX_REQUEST' => 'true']);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($message, $crawler->filterXPath('//*[@id="ticket-messages"]')->text());
            $this->assertReplyWasRecorded($id, $admin, $message);
        }

        $client->request('POST', $url, ['_token' => $token, 'message' => 'Reply without JavaScript']);
        self::assertResponseRedirects('/tickets/'.$id, 303);

        $client->jsonRequest('POST', '/api/v1/customer/tickets/'.$id.'/messages', ['message' => 'Reply from the API']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Reply from the API', $client->getResponse()->getContent());
        $this->assertReplyWasRecorded($id, $admin, 'Reply from the API');
    }

    private function createTicket(User $customer): int
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $ticket = new Ticket($customer, 'Support request', TicketCategory::Technical, TicketPriority::Normal);
        $manager->persist($ticket);
        $manager->flush();
        $id = $ticket->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function assertReplyWasRecorded(int $ticketId, User $recipient, string $body): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $message = $manager->getRepository(TicketMessage::class)->findOneBy(['ticket' => $ticketId, 'body' => $body]);
        self::assertInstanceOf(TicketMessage::class, $message);
        $notification = $manager->getRepository(Notification::class)->findOneBy([
            'recipient' => $recipient->getId(),
            'eventKey' => sprintf('ticket.message.%d.%d', $ticketId, $message->getId()),
        ]);
        self::assertInstanceOf(Notification::class, $notification);
        $audit = $manager->getRepository(AuditLog::class)->findOneBy(['action' => 'ticket.message.created'], ['id' => 'DESC']);
        self::assertInstanceOf(AuditLog::class, $audit);
        self::assertSame($message->getId(), $audit->getPayload()['message_id']);
    }
}
