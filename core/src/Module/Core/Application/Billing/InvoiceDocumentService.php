<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\Invoice;
use App\Repository\InvoiceArchiveRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

final readonly class InvoiceDocumentService
{
    public function __construct(private InvoiceLayoutRenderer $layouts, private InvoicePdfRenderer $pdf, private InvoiceArchiveRepository $archives, private AppSettingsService $settings)
    {
    }

    public function generate(Invoice $invoice, Request $request): string
    {
        return $this->pdf->render($this->layouts->render($invoice, $request, $this->settings->getInvoiceLayout()));
    }

    public function download(Invoice $invoice, Request $request): Response
    {
        $archive = $this->archives->findOneBy(['invoice' => $invoice]);
        $content = $archive !== null && $archive->getContentType() === 'application/pdf' ? $archive->getPdfContents() : $this->generate($invoice, $request);
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $invoice->getNumber()).'.pdf';

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
