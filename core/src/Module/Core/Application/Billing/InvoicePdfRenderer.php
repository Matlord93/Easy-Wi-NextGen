<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use Dompdf\Dompdf;
use Dompdf\Options;

final class InvoicePdfRenderer
{
    public function render(string $html): string
    {
        self::assertCapabilities($html);
        if (strlen($html) > 500000) {
            throw new \InvalidArgumentException('billing.layout_too_large');
        }
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols(['data://']);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml('<!doctype html><html><head><meta charset="UTF-8"><style>@page { margin: 35pt; } body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; } tr { page-break-inside: avoid; } thead { display: table-header-group; }</style></head><body>'.$html.'</body></html>', 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }

    public static function assertCapabilities(string $html): void
    {
        if (false !== stripos($html, 'data:image/png') && !extension_loaded('gd')) {
            throw new \InvalidArgumentException('billing.gd_required');
        }
    }
}
