<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Ticket;
use App\Module\Core\Domain\Entity\TicketAttachment;
use App\Module\Core\Domain\Entity\TicketMessage;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketCategory;
use App\Module\Core\Domain\Enum\TicketPriority;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerTicketController;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomerTicketControllerTest extends TestCase
{
    public function testSingleAndMultipleUploadsAreNormalizedToFileLists(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ticket-upload-');
        self::assertIsString($path);
        file_put_contents($path, 'attachment');
        try {
            $file = new UploadedFile($path, 'notes.txt', 'text/plain', UPLOAD_ERR_OK, true);
            $controller = (new \ReflectionClass(CustomerTicketController::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod($controller, 'attachmentFiles');
            $single = Request::create('/tickets', 'POST', files: ['attachments' => $file]);
            $multiple = Request::create('/tickets', 'POST', files: ['attachments' => [$file]]);

            self::assertSame([$file], $method->invoke($controller, $single));
            self::assertSame([$file], $method->invoke($controller, $multiple));
            self::assertSame([], $method->invoke($controller, Request::create('/tickets', 'POST')));
        } finally {
            unlink($path);
        }
    }

    public function testUnreadableFileSizeCannotPassAttachmentValidation(): void
    {
        $file = $this->createStub(UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn(false);
        $controller = (new \ReflectionClass(CustomerTicketController::class))->newInstanceWithoutConstructor();

        self::assertSame('Attachment exceeds the maximum size of 10 MB.', (new \ReflectionMethod($controller, 'validateAttachmentFiles'))->invoke($controller, [$file]));
    }

    public function testDownloadStreamsTheAllowedPathAndClosesTheStream(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'attachment');
        rewind($stream);
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::once())->method('fileExists')->with('ticket-attachments/2026/11/notes.txt')->willReturn(true);
        $storage->expects(self::once())->method('fileSize')->with('ticket-attachments/2026/11/notes.txt')->willReturn(10);
        $storage->expects(self::once())->method('readStream')->with('ticket-attachments/2026/11/notes.txt')->willReturn($stream);
        $controller = $this->controllerWithStorage($storage);
        $response = (new \ReflectionMethod($controller, 'streamAttachment'))->invoke($controller, $this->attachment('ticket-attachments/2026/11/notes.txt'));
        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame('attachment', $contents);
        self::assertSame('10', $response->headers->get('Content-Length'));
        self::assertFalse(is_resource($stream));
    }

    public function testDownloadRejectsTraversalBeforeAccessingStorage(): void
    {
        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects(self::never())->method('fileExists');
        $storage->expects(self::never())->method('readStream');
        $controller = $this->controllerWithStorage($storage);

        $response = (new \ReflectionMethod($controller, 'streamAttachment'))->invoke($controller, $this->attachment('ticket-attachments/../../secret'));

        self::assertSame(404, $response->getStatusCode());
    }

    private function controllerWithStorage(FilesystemOperator $storage): CustomerTicketController
    {
        $controller = (new \ReflectionClass(CustomerTicketController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($controller, 'storage'))->setValue($controller, $storage);
        (new \ReflectionProperty($controller, 'translator'))->setValue($controller, $this->createStub(TranslatorInterface::class));

        return $controller;
    }

    private function attachment(string $path): TicketAttachment
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $ticket = new Ticket($customer, 'Support', TicketCategory::Technical, TicketPriority::Normal);
        $message = new TicketMessage($ticket, $customer, 'Details');

        return new TicketAttachment($ticket, $message, $customer, 'notes.txt', $path, 'text/plain', 10);
    }
}
